import time

"""
这是一个多行注释
可以跨越多行
"""
print("Hello, World!")


def hello1():
    print("sub hello")


class Person(object):
    # 定义属性
    name = 'linecol'
    age = 33

    def __init__(self, name, age):
        self.name = name
        self.age = age

    def setName(self, name):
        self.name = name
        return self

    def getName(self):
        #time.sleep(10)
        return self.name
