import json

data = {'name': 'Alice', 'age': 30}
json_str = json.dumps(data)
print(json_str)

json.data = json.loads(json_str)