Работа с Иерархией Аккаунтов
Методы API работают только для основного аккаунта компании. Для работы с чатами компании нужно использовать ключ авторизации компании. Использование методов API с ключом авторизации сотрудника приведёт к ограничениям, описанным ниже:

Получение списка чатов не будет возвращать чаты по объявлениям, где пользователь является привязанным сотрудником, а не автором объявления.
Следующие методы вернут ошибку при использовании ключа авторизации сотрудника для объявлений компании:

Просмотр чата и сообщений в чате.
Отправка, удаление или отметка сообщения прочитанным в чате.
Добавление пользователя в черный список в чате.
Отправка изображений в чате.
Получение и загрузка голосовых сообщений.
Сообщения от чат-ботов Авито
Сообщения от чат-ботов Авито доступны для чтения так же, как и сообщения от пользователей.

Чтобы отличить сообщения чат-ботов, используется специальный тип – поле type в объекте Message — для сообщений чат-ботов он равен system. У таких сообщений заполнено поле flow_id. Оно содержит идентификатор чат-бота, отправившего сообщение.

Популярные чат-боты Авито
Здесь представлены идентификаторы наиболее популярных или полезных чат-ботов Авито.

seller_audience_discount: персональное предложение (скидка и т.п.) от продавца покупателю;
sbc_seller_notification: уведомление продавцу о принятии покупателем персонального предложения;
flower_161071: автоответы на частые вопросы покупателей, настраиваемые продавцами в некоторых категориях;
Authentication
AuthorizationCode
Это API использует OAuth 2 с механизмом authorization_code. Используйте его для доступа к данным других пользователей при разработке стороннего приложения. Подробнее

Security Scheme Type	OAuth2
authorizationCode OAuth Flow	
Authorization URL: https://avito.ru/oauth
Token URL: https://api.avito.ru/token
Scopes:
autoload:reports - Получение отчетов Автозагрузки
items:apply_vas - Применение дополнительных услуг
items:info - Получение информации об объявлениях
job:applications - Получение информации об откликах на вакансии
job:cv - Получение информации резюме
job:write - Изменение объявлений вертикали Работа
messenger:read - Чтение сообщений в мессенджере Авито
messenger:write - Модифицирование сообщений в мессенджере Авито
short_term_rent:read - Получение информации об объявлениях краткосрочной аренды
short_term_rent:write - Изменение объявлений краткосрочной аренды
stats:read - Получение статистики объявлений
user:read - Получение информации о пользователе
user_balance:read - Получение баланса пользователя
user_operations:read - Получение истории операций пользователя
ClientCredentials
Это API использует OAuth 2 с механизмом client_credentials. Используйте его для доступа к возможностям своей личной учетной записи. Подробнее

Security Scheme Type	OAuth2
clientCredentials OAuth Flow	
Token URL: https://api.avito.ru/token
Scopes:
Messenger API
API для интеграции между мессенджером Авито и сторонней системой в обе стороны.

Отправка сообщения
На данный момент можно отправить только текстовое сообщение

Authorizations:
(messenger:write) AuthorizationCodeClientCredentials
path Parameters
user_id
required
integer <int64>
Идентификатор пользователя (клиента)

chat_id
required
string
Идентификатор чата (клиента)

header Parameters
Authorization
required
string
Example: Bearer ACCESS_TOKEN
Токен для авторизации

Request Body schema: application/json
Отправление сообщения

message	
object
type	
string
Value: "text"
Тип сообщения

Responses
200 Успешный ответ

post
/messenger/v1/accounts/{user_id}/chats/{chat_id}/messages
Request samples
Payload
Content type
application/json

Copy
Expand allCollapse all
{
"message": {
"text": "string"
},
"type": "text"
}
Response samples
200
Content type
application/json

Copy
Expand allCollapse all
{
"content": {
"text": "привет"
},
"created": 1563202313,
"direction": "out",
"id": "bdcc5bac2d00345f1cc66fa657813958",
"type": "text"
}
Отправка сообщения с изображением
Метод используется для отправки сообщения с изображением.

Для отправки сообщения с изображением необходимо передать в запросе id изображения, полученного после загрузки.

Authorizations:
(messenger:write) AuthorizationCodeClientCredentials
path Parameters
user_id
required
integer <int64>
Идентификатор пользователя (клиента)

chat_id
required
string
Идентификатор чата (клиента)

header Parameters
Authorization
required
string
Example: Bearer ACCESS_TOKEN
Токен для авторизации

Request Body schema: application/json
Вложение с изображением

image_id
required
string
Идентификатор загруженного изображения

Responses
200 Успешный ответ

post
/messenger/v1/accounts/{user_id}/chats/{chat_id}/messages/image
Request samples
Payload
Content type
application/json

Copy
Expand allCollapse all
{
"image_id": "string"
}
Response samples
200
Content type
application/json

Copy
Expand allCollapse all
{
"author_id": 9623532,
"content": {
"image": {}
},
"created": 1563202313,
"direction": "out",
"id": "bdcc5bac2d00345f1cc66fa657813958",
"type": "image"
}
Удаление сообщения
Сообщение не пропадает из истории, а меняет свой тип на deleted. Удалять сообщения можно не позднее часа с момента их отправки.

Authorizations:
(messenger:write) AuthorizationCodeClientCredentials
path Parameters
user_id
required
integer <int64>
Идентификатор пользователя (клиента)

chat_id
required
string
Идентификатор чата (клиента)

message_id
required
string
Идентификатор сообщения

header Parameters
Authorization
required
string
Example: Bearer ACCESS_TOKEN
Токен для авторизации

Responses
200 Успешный ответ

post
/messenger/v1/accounts/{user_id}/chats/{chat_id}/messages/{message_id}
Response samples
200
Content type
application/json

Copy
Expand allCollapse all
{ }
Прочитать чат
После успешного получения списка сообщений необходимо вызвать этот метод для того, чтобы чат стал прочитанным.

Authorizations:
(messenger:read) AuthorizationCodeClientCredentials
path Parameters
user_id
required
integer <int64>
Идентификатор пользователя (клиента)

chat_id
required
string
Идентификатор чата (клиента)

header Parameters
Authorization
required
string
Example: Bearer ACCESS_TOKEN
Токен для авторизации

Responses
200 Успешный ответ

post
/messenger/v1/accounts/{user_id}/chats/{chat_id}/read
Response samples
200
Content type
application/json

Copy
Expand allCollapse all
{
"ok": true
}
Получение голосовых сообщений
Метод используется для получения ссылки на файл с голосовым сообщением по идентификатору voice_id, получаемому из тела сообщения с типом voice.

Особенности работы с голосовыми сообщениями:

Голосовые сообщения Авито используют кодек opus внутри .mp4 контейнера;
Ссылка на голосовое сообщение доступна в течение одного часа с момента запроса. Попытка получить файл по ссылке спустя это время приведёт к ошибке. Для восстановления доступа необходимо получить новую ссылку на файл;
Как и с обычными сообщениями, получение ссылки на файл доступно только для пользователей, находящихся в беседе, где голосовое сообщение было отправлено;
Authorizations:
(messenger:read) AuthorizationCodeClientCredentials
path Parameters
user_id
required
integer <int64>
Идентификатор пользователя (клиента)

query Parameters
voice_ids
required
Array of strings
Получение файлов голосовых сообщений с указанными voice_id

header Parameters
Authorization
required
string
Example: Bearer ACCESS_TOKEN
Токен для авторизации

Responses
200 Успешный ответ

get
/messenger/v1/accounts/{user_id}/getVoiceFiles
Response samples
200
Content type
application/json

Copy
Expand allCollapse all
{
"voices_urls": {
"property1": "https://avito.ru/voice_url",
"property2": "https://avito.ru/voice_url"
}
}
Загрузка изображений
Метод используется для загрузки изображений в формате JPEG, HEIC, GIF, BMP или PNG.

Особенности работы с загрузкой изображений:

Метод поддерживает только одиночные изображения; для загрузки нескольких картинок необходимо сделать несколько запросов;
Максимальный размер файла — 24 МБ;
Максимальное разрешение — 75 мегапиксилей;
Authorizations:
(messenger:write) AuthorizationCodeClientCredentials
path Parameters
user_id
required
integer <int64>
Идентификатор пользователя (клиента)

header Parameters
Authorization
required
string
Example: Bearer ACCESS_TOKEN
Токен для авторизации

Request Body schema: multipart/form-data
uploadfile[]
required
string <binary>
Responses
200 Успешный ответ

post
/messenger/v1/accounts/{user_id}/uploadImages
Response samples
200
Content type
application/json

Copy
Expand allCollapse all
{
"12345.12345abcdefghijklm": {
"1280x960": "https://example.hosts.ru/image_1280_960.png",
"140x105": "https://example.hosts.ru/image_140_105.png",
"32x32": "https://example.hosts.ru/image_32_32.png"
}
}
Получение подписок (webhooks)
Получение списка подписок

Authorizations:
(messenger:read) AuthorizationCodeClientCredentials
header Parameters
Authorization
required
string
Example: Bearer ACCESS_TOKEN
Токен для авторизации

Responses
200 Успешный ответ

post
/messenger/v1/subscriptions
Response samples
200
Content type
application/json

Copy
Expand allCollapse all
{
"subscriptions": [
{}
]
}
Отключение уведомлений (webhooks)
Отключение уведомлений

Authorizations:
(messenger:read) AuthorizationCodeClientCredentials
header Parameters
Authorization
required
string
Example: Bearer ACCESS_TOKEN
Токен для авторизации

Request Body schema: application/json
Url, на который необходимо перестать слать уведомления

url
required
string
Url на который будут отправляться нотификации

Responses
200 Успешный ответ

post
/messenger/v1/webhook/unsubscribe
Request samples
PayloadPHP
Content type
application/json

Copy
Expand allCollapse all
{
"url": "string"
}
Response samples
200
Content type
application/json

Copy
Expand allCollapse all
{
"ok": true
}
Добавление пользователя в blacklist
Добавление пользователя в blacklist

Authorizations:
(messenger:write) AuthorizationCodeClientCredentials
path Parameters
user_id
required
integer <int64>
Идентификатор пользователя (клиента)

header Parameters
Authorization
required
string
Example: Bearer ACCESS_TOKEN
Токен для авторизации

Request Body schema: application/json
Добавление пользователя в blacklist

users	
Array of objects
Responses
200 Успешный ответ

post
/messenger/v2/accounts/{user_id}/blacklist
Request samples
Payload
Content type
application/json

Copy
Expand allCollapse all
{
"users": [
{}
]
}
Получение информации по чатам
Возвращает список чатов

Authorizations:
(messenger:read) AuthorizationCodeClientCredentials
path Parameters
user_id
required
integer <int64>
Идентификатор пользователя (клиента)

query Parameters
item_ids	
Array of integers <int64>
Example: item_ids=12345,6789
Получение чатов только по объявлениям с указанными item_id

unread_only	
boolean
Default: false
Example: unread_only=true
При значении true метод возвращает только непрочитанные чаты

chat_types	
Array of strings
Default: "u2i"
Items Enum: "u2i" "u2u"
Example: chat_types=u2i,u2u
Фильтрация возвращаемых чатов.

u2i — чаты по объявлениям;
u2u — чаты между пользователями;
limit	
integer <int32> [ 1 .. 100 ]
Default: 100
Example: limit=50
Количество сообщений / чатов для запроса

offset	
integer <int32> [ 0 .. 1000 ]
Default: 0
Example: offset=50
Сдвиг сообщений / чатов для запроса

header Parameters
Authorization
required
string
Example: Bearer ACCESS_TOKEN
Токен для авторизации

Responses
200 Успешный ответ

get
/messenger/v2/accounts/{user_id}/chats
Response samples
200
Content type
application/json

Copy
Expand allCollapse all
{
"chats": [
{}
]
}
Получение информации по чату
Возвращает данные чата и последнее сообщение в нем

Authorizations:
(messenger:read) AuthorizationCodeClientCredentials
path Parameters
user_id
required
integer <int64>
Идентификатор пользователя (клиента)

chat_id
required
string
Идентификатор чата (клиента)

header Parameters
Authorization
required
string
Example: Bearer ACCESS_TOKEN
Токен для авторизации

Responses
200 Успешный ответ

get
/messenger/v2/accounts/{user_id}/chats/{chat_id}
Response samples
200
Content type
application/json

Copy
Expand allCollapse all
{
"context": {
"type": "item",
"value": {}
},
"created": 1571412836,
"id": "string",
"last_message": {
"author_id": 94235311,
"content": {},
"created": 1571654040,
"direction": "out",
"id": "string",
"type": "link"
},
"updated": 1571654040,
"users": [
{}
]
}
Получение списка сообщений V3
Получение списка сообщений. Не помечает чат прочитанным. После успешного получения списка сообщений необходимо вызвать метод, который сделает сообщения прочитанными. Для получения новых сообщений в реальном времени используйте webhooks

Authorizations:
(messenger:read) AuthorizationCodeClientCredentials
path Parameters
user_id
required
integer <int64>
Идентификатор пользователя (клиента)

chat_id
required
string
Идентификатор чата (клиента)

query Parameters
limit	
integer <int32> [ 1 .. 100 ]
Default: 100
Example: limit=50
Количество сообщений / чатов для запроса

offset	
integer <int32> [ 0 .. 1000 ]
Default: 0
Example: offset=50
Сдвиг сообщений / чатов для запроса

header Parameters
Authorization
required
string
Example: Bearer ACCESS_TOKEN
Токен для авторизации

Responses
200 Успешный ответ

get
/messenger/v3/accounts/{user_id}/chats/{chat_id}/messages/
Response samples
200
Content type
application/json

Copy
Expand allCollapse all
[
{
"author_id": 94235311,
"content": {},
"created": 1571654040,
"direction": "out",
"id": "string",
"is_read": true,
"quote": {},
"read": 123,
"type": "text"
}
]
Включение уведомлений V3 (webhooks)
Включение webhook-уведомлений.

Схему JSON приходящего в webhook сообщения можно увидеть в примерах ответов.

После регистрации url'а для получения веб-хуков, убедитесь, что он доступен, работает и возвращает статус 200 ОК соблюдая timeout 2s, например, выполнив запрос:

curl --connect-timeout 2 <url-вашего-вебхука> -i -d '{}'

Authorizations:
(messenger:read) AuthorizationCodeClientCredentials
header Parameters
Authorization
required
string
Example: Bearer ACCESS_TOKEN
Токен для авторизации

Request Body schema: application/json
Url на который будут отправляться уведомления

url
required
string
Url на который будут отправляться нотификации

Responses
200 Успешный ответ
201 JSON сообщения, который будет приходить в webhook

post
/messenger/v3/webhook
Request samples
PayloadPHP
Content type
application/json

Copy
Expand allCollapse all
{
"url": "string"
}
Response samples
200201
Content type
application/json

Copy
Expand allCollapse all
{
"ok": true
}
