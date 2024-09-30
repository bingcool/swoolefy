from pypinyin import pinyin, Style
import logging

# 示例中文文本
text = "你好，世界"

# 转换为拼音，默认输出带声调的拼音
pinyin_list = pinyin(text, style=Style.TONE3)
print(pinyin_list)  # 输出: [['ni3'], ['hao3'], ['shi4'], ['jie4']]

# 转换为拼音，输出不带声调的拼音
pinyin_list = pinyin(text, style=Style.NORMAL)
print(pinyin_list)  # 输出: [['ni'], ['hao'], ['shi'], ['jie']]

logging.basicConfig(level=logging.DEBUG)
logging.info("这是一个信息")