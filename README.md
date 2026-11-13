# Veylo API

Laravel backend for the **Veylo** Expo / React Native app.

Backend repository: https://github.com/beastcodes27/dar-event-backend

Mobile app repository: https://github.com/beastcodes27/DarEvent

The backend and mobile app are independent repositories. Clone this repository
separately from the mobile app and commit backend changes here. Set the mobile
app’s `EXPO_PUBLIC_API_URL` to the running API’s `/api` URL.

## Features

- Token authentication with Laravel Sanctum (register, login, logout, password change)
- User profiles, avatars, follows / followers
- Event CRUD, categories, public/private events, feeds
- Likes, bookmarks/saved events, comments, replies and comment likes
- Search across events and users (events, hosts, locations, categories)
- Recent searches
- Database notifications (follows, likes, comments, replies)
- Verification applications with document upload and mock payment state
- Story highlights, stories and live flags
- Ticket booking and cancellation
- Seeded demo data matching the app's mock screens
- Feature tests for the main API flows

## Requirements

- PHP 8.4+
- Composer
- SQLite (default) or MySQL/PostgreSQL

## Setup

```bash
git clone https://github.com/beastcodes27/dar-event-backend.git
cd dar-event-backend
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed
php artisan storage:link
php artisan serve
```

The API runs at `http://localhost:8000/api`.

For the mobile app:

| Environment | API base URL |
| --- | --- |
| Web / iOS simulator | `http://localhost:8000/api` |
| Android emulator | `http://10.0.2.2:8000/api` |
| Physical device | `http://<your-computer-LAN-IP>:8000/api` |

When testing on a device, set `APP_URL` in the backend `.env` to the same host
(for example `http://192.168.1.20:8000`) so event/avatar image URLs are
reachable, then run `php artisan config:clear`.

## Demo accounts

All seeded users use the password **`password`**.

| Email | Username |
| --- | --- |
| `iman@darevent.app` | `iman_ibraah` |
| `djkhaled@darevent.app` | `dj_khaled` |
| `eventpro@darevent.app` | `eventpro` |
| `chefmarco@darevent.app` | `chef_marco` |

The seeder creates 22 users, 16 events, comments, replies, likes, saves,
follows, highlights, stories, recent searches and a ticket.

## Authentication

Send the token returned by register/login as a bearer token:

```http
Authorization: Bearer <token>
Accept: application/json
```

```bash
curl -X POST http://localhost:8000/api/auth/login \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"login":"iman_ibraah","password":"password"}'
```

## Response format

Single resources are wrapped in `data`. Paginated endpoints also return
`links` and `meta`:

```json
{
  "data": {
    "id": 1,
    "title": "Summer Beach Party",
    "slug": "summer-beach-party",
    "date": "Sat, Nov 28 · 8:00 PM",
    "host": { "id": 2, "name": "DJ Khaled", "username": "dj_khaled" },
    "liked": false,
    "saved": false,
    "comments_list": []
  }
}
```

Validation errors use Laravel's standard `422` shape with `message` and
`errors`.

### Frontend field mapping

The API uses `snake_case`, but it also accepts the app's camelCase aliases where
it matters: `fullName` → `name`, `confirmPassword` → `password_confirmation`,
`termsAccepted` → `terms`, and comment/recent-search `text` → `body`/`query`.

| App field | API field |
| --- | --- |
| `commentsList` | `comments_list` |
| `comment.text` | `comment.body` / `comment.text` |
| `comment.user` | `comment.user` (username) or `comment.username` |
| `comment.time` | `comment.time` (human readable) |
| `event.likes` | `event.likes_count` |
| `event.image` | `event.image` (absolute URL) |
| `story.avatar` / `event.avatar` | `initials` on user / `avatar` on event |

## Endpoints

### Public

| Method | Endpoint | Description |
| --- | --- | --- |
| `POST` | `/auth/register` | Create an account and return a token |
| `POST` | `/auth/login` | Login with `login` (email/username) or `email` |
| `GET` | `/categories` | Event and profile categories |
| `GET` | `/events` | List public events (`q`, `category`, `host`, `location`, `upcoming`, `sort=soon\|latest\|popular`, `per_page`) |
| `GET` | `/events/{id-or-slug}` | Event detail with comments/replies |
| `GET` | `/events/{event}/comments` | Event comments |
| `GET` | `/search` | Search (`q`, `type=all\|events\|users`, `category`, `limit`) |
| `GET` | `/users/{username}` | Public profile |
| `GET` | `/users/{username}/events` | Events hosted by a user |
| `GET` | `/users/{username}/followers` | Followers |
| `GET` | `/users/{username}/following` | Following |
| `GET` | `/users/{username}/highlights` | Profile highlights |
| `GET` | `/users/{username}/stories` | Active stories |
| `GET` | `/stories` | All active stories |

### Authenticated (`auth:sanctum`)

| Method | Endpoint | Description |
| --- | --- | --- |
| `GET` | `/auth/me` | Current user |
| `POST` | `/auth/logout` | Revoke current token |
| `POST` | `/auth/password` | Change password |
| `GET` | `/profile` | Own profile |
| `PATCH` | `/profile` | Update name, username, email, bio, category, gender, country |
| `POST` | `/profile/avatar` | Upload avatar (`avatar`, multipart) |
| `DELETE` | `/profile/avatar` | Remove avatar |
| `GET` | `/feed` | Discover public events + own private events (blocked accounts excluded) |
| `GET` | `/my-events` | Own events, including private |
| `POST` | `/events` | Create event (`image` file or `image_url`) |
| `PUT/PATCH` | `/events/{event}` | Update own event |
| `DELETE` | `/events/{event}` | Delete own event |
| `POST` | `/events/{event}/like` | Toggle like |
| `POST` | `/events/{event}/save` | Toggle bookmark |
| `GET` | `/saved-events` | Bookmarked events |
| `POST` | `/events/{event}/comments` | Add comment or reply (`parent_id`) |
| `DELETE` | `/comments/{comment}` | Delete own comment, or as event host |
| `POST` | `/comments/{comment}/like` | Toggle comment like |
| `POST` | `/users/{username}/follow` | Toggle follow |
| `GET` | `/notifications` | Notifications + `meta.unread_count` |
| `GET` | `/notifications/unread-count` | Unread count |
| `POST` | `/notifications/{id}/read` | Mark one as read |
| `POST` | `/notifications/read-all` | Mark all as read |
| `DELETE` | `/notifications/{id}` | Delete notification |
| `GET` | `/recent-searches` | Last 10 searches |
| `POST` | `/recent-searches` | Save search (`query`, `type`, `subtitle`) |
| `DELETE` | `/recent-searches/{id}` | Remove one search |
| `DELETE` | `/recent-searches` | Clear all searches |
| `GET` | `/verification-requests` | Own verification applications |
| `POST` | `/verification-requests` | Submit application (`account_type=gov\|ngo`, `document`, `paid`) |
| `GET` | `/story-highlights` | Own highlights |
| `POST` | `/story-highlights` | Create highlight (`label`, `icon`, `cover`) |
| `DELETE` | `/story-highlights/{id}` | Delete own highlight |
| `POST` | `/stories` | Publish story (`media`, `caption`, `is_live`) |
| `DELETE` | `/stories/{id}` | Delete own story |
| `GET` | `/tickets` | Own tickets |
| `POST` | `/events/{event}/tickets` | Book tickets (`quantity`) |
| `GET` | `/tickets/{id}` | Ticket detail |
| `POST` | `/tickets/{id}/cancel` | Cancel ticket |

## Event payload example

```json
{
  "title": "Rooftop Sunset Party",
  "description": "Live DJ, food and skyline views.",
  "category": "music",
  "location": "Dar es Salaam",
  "venue": "Sky Lounge",
  "starts_at": "2026-12-12T18:30:00Z",
  "ends_at": "2026-12-12T23:00:00Z",
  "price": 25,
  "currency": "USD",
  "has_video": false,
  "is_public": true
}
```

Categories: `music`, `university`, `birthday`, `weddings`, `education`,
`business`, `other`.

Profile categories: `Digital Creator`, `NGO's`, `Education`,
`Event Organizer`, `Artist`, `Business`, `Personal`.

Genders: `Male`, `Female`, `Other`, `Prefer not to say`.

## Notes

- Uploaded files are stored on the `public` disk and require `php artisan storage:link`.
- Verification payments are intentionally mocked: pass `paid: true` to mark the
  application as paid. Review/approval is an admin workflow that is not exposed
  in the mobile app yet.
- Notification delivery uses Laravel's database channel; no mail/push provider is
  required to run the app.
- Event route bindings accept both the numeric id and the slug.

## Testing

```bash
php artisan test
./vendor/bin/pint
```
