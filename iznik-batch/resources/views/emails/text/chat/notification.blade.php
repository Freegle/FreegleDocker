{{ $chatMessage['text'] }}
@if($chatMessage['imageUrl'])
Here's a picture: {{ $chatMessage['imageUrl'] }}
@endif

-------
This is a text-only version of the message; you can also view this message in HTML if you have it turned on, and on the website. We're adding this because short text messages don't always get delivered successfully.
