import os
import time
import sys
import atexit
import psutil
from datetime import datetime

def hello():
    print("Hello from Python!")
    return 1111

def getMemorystate():
    phymem = psutil.virtual_memory()
    line = "Memory: %5s%% %6s/%s"%(
            phymem.percent,
            str(int(phymem.used/1024/1024))+"M",
            str(int(phymem.total/1024/1024))+"M"
            )
    return line

def getCpuState():
    cpu_percent = psutil.cpu_percent(interval=1)
    return "CPU: %5s%%"%cpu_percent

def argsd(a1, a2):
        print(a1)
        print(a2)

def print_info(info):
    for key, value in info.items():
        print(f"{key}: {value}")

def get_current_date():
    current_datetime = datetime.now()
    current_date = current_datetime.date()
    return current_date

def get_list():
    return [1,2,3]

def _nest_dict_rec(k, v, out):
    k, *rest = k.split('_', 1)
    if rest:
        _nest_dict_rec(rest[0], v, out.setdefault(k, {}))
    else:
        out[k] = v

def get_dict():
    flat = {
        'X_a_one': 10,
        'X_a_two': 20,
        'X_b_one': 10,
        'X_b_two': 20,
        'Y_a_one': 10,
        'Y_a_two': 20,
        'Y_b_one': 10,
        'Y_b_two': 20
        }
    result = {}
    for k, v in flat.items():
        _nest_dict_rec(k, v, result)
    return result

