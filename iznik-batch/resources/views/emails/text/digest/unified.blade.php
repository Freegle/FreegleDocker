{{ $postCount }} new post{{ $postCount === 1 ? '' : 's' }} near you
====================================

Hi {{ $user->displayname ?? 'there' }},

Here {{ $postCount === 1 ? 'is' : 'are' }} {{ $postCount }} new post{{ $postCount === 1 ? '' : 's' }} from your Freegle communities:

@foreach($posts as $post)
{{ strtoupper($post['type']) }}: {{ $post['itemName'] }}
@if($post['messageText'])
{{ \Illuminate\Support\Str::limit($post['messageText'], 150) }}
@endif
@if($post['postedToText'])
{{ $post['postedToText'] }}
@endif
View: {{ $post['messageUrl'] }}

@endforeach
------------------------------------

Browse all posts: {{ $browseUrl }}

------------------------------------

You're receiving this because you're a member of Freegle. These emails are sent daily.

Update your settings: {{ $settingsUrl }}

------------------------------------

Freegle - Don't throw it away, give it away!
{!! config('freegle.sites.user') !!}
