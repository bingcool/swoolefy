#!/usr/bin/env python3
"""Convert swoolefy script-style *Test.php into a PHPUnit TestCase class."""

from __future__ import annotations

import re
import sys
from pathlib import Path


def convert(src: Path, dest: Path, namespace: str, class_name: str, base: str) -> None:
    text = src.read_text()
    # Strip shebang-less leading require of autoload
    text = re.sub(
        r"^require\s+dirname\([^;]+;\s*\n",
        "",
        text,
        count=1,
        flags=re.M,
    )

    # Remove assertTrue / pass helpers
    text = re.sub(
        r"/\*\*[^*]*\*+(?:[^/*][^*]*\*+)*/\s*function assertTrue\(bool \$condition, string \$message\): void\s*\{[^}]+}\s*",
        "",
        text,
        flags=re.S,
    )
    text = re.sub(
        r"function assertTrue\(bool \$condition, string \$message\): void\s*\{[^}]+}\s*",
        "",
        text,
    )
    text = re.sub(
        r"/\*\*[^*]*\*+(?:[^/*][^*]*\*+)*/\s*function pass\(string \$name\): void\s*\{[^}]+}\s*",
        "",
        text,
        flags=re.S,
    )
    text = re.sub(
        r"function pass\(string \$name\): void\s*\{[^}]+}\s*",
        "",
        text,
    )

    # Remove runner block: $tests = [...]; foreach ... echo
    text = re.sub(
        r"\$tests\s*=\s*\[[^\]]*\];\s*"
        r"(?:\$passed\s*=\s*0;\s*)?"
        r"foreach\s*\(\$tests.*?\)\s*\{.*?\}\s*"
        r"(?:SupportLog::resetTestHandler\(\);\s*)?"
        r"(?:echo[^\n]*\n)?",
        "",
        text,
        flags=re.S,
    )
    # Alternate runner styles
    text = re.sub(
        r"foreach\s*\(\s*\$tests\s+as\s+\$name\s*=>\s*\$fn\s*\)\s*\{.*?\}\s*"
        r"(?:echo[^\n]*\n)?",
        "",
        text,
        flags=re.S,
    )
    text = re.sub(r"Swoole\\Coroutine\\run\s*\(static function\s*\(\)\s*use\s*\(\$tests\).*?\}\);\s*", "", text, flags=re.S)

    # Collect use statements and file-level docblock
    uses = re.findall(r"^use\s+[^;]+;", text, flags=re.M)
    # Remove use lines from body for re-emit
    body = re.sub(r"^use\s+[^;]+;\s*\n", "", text, flags=re.M)

    # Remove top <?php and declare if present — we re-emit
    body = re.sub(r"^<\?php\s*", "", body)
    body = re.sub(r"^declare\(strict_types=1\);\s*", "", body)

    # Extract file-level docblock (first /** */)
    file_doc = ""
    m = re.match(r"\s*(/\*\*.*?\*/)\s*", body, flags=re.S)
    if m:
        file_doc = m.group(1)
        body = body[m.end() :]

    # Convert function testX to public methods — only top-level functions named test*
    def repl_test(match: re.Match) -> str:
        doc = match.group(1) or ""
        name = match.group(2)
        args = match.group(3)
        fn_body = match.group(4)
        fn_body = re.sub(r"\bassertTrue\(", "$this->assertTrue(", fn_body)
        fn_body = re.sub(r"\bpass\([^)]*\);\s*", "", fn_body)
        return f"{doc}    public function {name}({args}): void\n    {{{fn_body}\n    }}\n"

    # Split: keep final classes outside the test class
    class_chunks = list(re.finditer(r"^(final\s+class\s+\w+.*?^})", body, flags=re.M | re.S))
    helpers_and_tests = body
    stub_classes = []
    if class_chunks:
        # Remove final classes from body and collect
        for ch in reversed(class_chunks):
            stub_classes.insert(0, ch.group(0))
            helpers_and_tests = helpers_and_tests[: ch.start()] + helpers_and_tests[ch.end() :]

    # Convert test functions
    helpers_and_tests = re.sub(
        r"(/\*\*.*?\*/\s*)?function\s+(test\w+)\s*\(([^)]*)\)\s*:\s*void\s*\{(.*?)\n\}",
        repl_test,
        helpers_and_tests,
        flags=re.S,
    )

    # Convert remaining helper functions to private methods
    def repl_helper(match: re.Match) -> str:
        doc = match.group(1) or ""
        name = match.group(2)
        args = match.group(3)
        ret = match.group(4) or ""
        fn_body = match.group(5)
        fn_body = re.sub(r"\bassertTrue\(", "$this->assertTrue(", fn_body)
        fn_body = re.sub(r"\bpass\([^)]*\);\s*", "", fn_body)
        ret_s = f": {ret}" if ret else ""
        return f"{doc}    private function {name}({args}){ret_s}\n    {{{fn_body}\n    }}\n"

    helpers_and_tests = re.sub(
        r"(/\*\*.*?\*/\s*)?function\s+(?!test)(\w+)\s*\(([^)]*)\)\s*(?::\s*([^{\s]+))?\s*\{(.*?)\n\}",
        repl_helper,
        helpers_and_tests,
        flags=re.S,
    )

    # Indent methods that aren't already indented with 4 spaces at public/private
    # Clean SupportLog handlers at file level into setUp if present as bare calls
    setup_bits = []
    if "SupportLog::setTestHandler" in helpers_and_tests:
        m = re.search(
            r"SupportLog::setTestHandler\(static function\s*\(\)\s*:\s*void\s*\{.*?\}\);",
            helpers_and_tests,
            flags=re.S,
        )
        if m:
            setup_bits.append("        " + m.group(0).replace("\n", "\n        "))
            helpers_and_tests = helpers_and_tests[: m.start()] + helpers_and_tests[m.end() :]
    if "SupportLog::resetTestHandler" in helpers_and_tests:
        helpers_and_tests = re.sub(r"SupportLog::resetTestHandler\(\);\s*", "", helpers_and_tests)

    # Fix leftover top-level code (Coroutine\run wrappers for auth-style)
    # Leave as-is if still present — manual fix later

    use_block = "\n".join(dict.fromkeys(uses))
    if base.startswith("\\"):
        base_use = ""
        base_ref = base
    else:
        base_use = f"use {base};\n"
        base_ref = base.rsplit("\\", 1)[-1]

    setup_methods = ""
    if setup_bits:
        setup_methods = (
            "\n    protected function setUp(): void\n    {\n"
            "        parent::setUp();\n"
            + "\n".join(setup_bits)
            + "\n    }\n\n"
            "    protected function tearDown(): void\n    {\n"
            "        SupportLog::resetTestHandler();\n"
            "        parent::tearDown();\n"
            "    }\n"
        )

    # Ensure methods indented: if a line starts with "public function" without indent, indent whole class body lines
    methods_body = helpers_and_tests.strip()
    if methods_body and not methods_body.startswith("public") and "public function" in methods_body:
        pass

    # Indent class body by 4 spaces for non-empty lines that look like method starts at column 0
    lines = []
    for line in methods_body.splitlines():
        if re.match(r"^(public|private|protected)\s+function", line):
            lines.append("    " + line)
        elif line.startswith("/**") or line.startswith(" *") or line.startswith(" */"):
            # docblocks before methods — if at col 0, indent
            if not line.startswith("    "):
                lines.append("    " + line)
            else:
                lines.append(line)
        else:
            # inside methods: if previous was method and line not indented enough, keep
            lines.append(line if line.startswith("    ") or line == "" else "    " + line)
    methods_body = "\n".join(lines)

    stubs = "\n\n".join(stub_classes)
    if stubs:
        # Put stubs in same namespace
        stubs = "\n\n" + stubs

    out = f"""<?php

declare(strict_types=1);

namespace {namespace};

{use_block}
{base_use}
{file_doc}

final class {class_name} extends {base_ref}
{{{setup_methods}
{methods_body}
}}
{stubs}
"""
    dest.parent.mkdir(parents=True, exist_ok=True)
    dest.write_text(out)
    print(f"Wrote {dest}")


def main() -> None:
    if len(sys.argv) != 5:
        print(
            "Usage: convert_script_test.py <src> <dest> <namespace> <ClassName> [BaseFQCN]",
            file=sys.stderr,
        )
        # allow 5 args with base
    args = sys.argv[1:]
    if len(args) == 4:
        src, dest, ns, cls = args
        base = "Swoolefy\\Tests\\TestCase"
    elif len(args) == 5:
        src, dest, ns, cls, base = args
    else:
        sys.exit(2)
    convert(Path(src), Path(dest), ns, cls, base)


if __name__ == "__main__":
    main()
