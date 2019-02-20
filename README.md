# Push Notifications For Posts (pn4p)

WordPress plugin for handling devices registration and sending push notifications when posts are added/updated

## Building
Before using the plugin build the actual sender component (`push-notif-sender`, you'll need Go SDK):

`go build push-notif-sender`

## Installation

Zip the folder containing `index.html`, `push-notif-for-posts.php` and `push-notif-sender` binary and upload/install it on WordPress. You may need to enable uploading large files in PHP config for your server.

## Configuration

After installation and activation, plugin is available in WordPress side menu.

Before using the plugin, you should provide your FCM Server Key.

## Usage (Mobile apps)

After installation and activation, plugin exposes endpoint that mobile apps use for registration. App can call this endpoint whenever it wants.

POST `http://www.example.com/?rest_route=/pn4p/v1/token`

Parameters:

| Parameter	| Value			|
|---------------|-----------------------|
| token		| Device token		|
| platform	| `ios` or `android`	|
| device	| Device Name		|

Successful registration response:
```
{
    "success": true,
    "message": "Token registration successful"
}
```
Error response:

```
{
    "code": "rest_invalid_param",
    "message": "Invalid parameter(s): platform",
    "data": {
        "status": 400,
        "params": {
            "platform": "Invalid parameter."
        }
    }
}
```

Push notification delivered to a device will have payload like this:

```
{
	"type": "post_notification",
	"post": {
		"id": 55,
		"slug": "some-post-title",
		"title": "Some Post Title",
		"summary": "Post sumarry here",
		"photo": "http://example.com/wor2-07-16-29-12.png"
	}
}
```

## Usage (Plugin UI)

`Settings` tab exposes plugin settings.
`Registrations` tab shows list of registrations.
`Log` tab shows message log from `push-notif-sender` binary after post is created or updated.

When creating or editing post, check `Send Push Notification` option in the `Publish` WordPress menu if you want to send push notifications for that post. Note that failed deliveries of push notifications to certain devices (e.g. due to expired token) will remove that devices from registrations untill devices register again.

 