# TN Post Ingestion Design

- Poll [TN post API](https://redocly.github.io/redoc/?url=https%3A//trashnothing.com/api/v1.4/trashnothing-openapi.yaml#tag/posts/operation/get_posts) every N seconds
	- Query for all posts since last successful poll
	- Store polling results in DB

- Should also poll the [post changes API](https://redocly.github.io/redoc/?url=https%3A//trashnothing.com/api/v1.4/trashnothing-openapi.yaml#tag/posts/operation/get_all_posts_changes) to update posts
	- get all edited posts, then use the [retrieve multiple posts API](https://redocly.github.io/redoc/?url=https%3A//trashnothing.com/api/v1.4/trashnothing-openapi.yaml#tag/posts/operation/get_posts_by_ids) to get all new info
	- Store polling results in DB

- Do this polling and processing in separate [Laravel queue](https://laravel.com/docs/12.x/queues) so it doesn't block other things like messaging
	- Worst-case pulling in a new post could take a long time - e.g. downloading post photos, resizing/processing them, uploading to Freegle's image storage
    - TBD use Redis or DB for queue