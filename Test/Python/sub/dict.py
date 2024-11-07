import json


def test1():
	# 创建一个空字典
	empty_dict = {}
	
	# 创建一个包含键值对的字典
	person = {
		'name': 'Alice',
		'age': 30,
		'city': 'New York'
	}
	
	json_str = json.dumps(person)
	print(json_str)
	
	