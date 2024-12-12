import requests

print("Hello, World!")

response = requests.get('https://www.baidu.com')
print(response.text)