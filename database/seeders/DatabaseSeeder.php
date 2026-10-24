<?php

namespace Database\Seeders;

use App\Models\Comment;
use App\Models\Event;
use App\Models\RecentSearch;
use App\Models\Story;
use App\Models\StoryHighlight;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->publishSeedImages();

        $users = $this->seedUsers();

        $events = $this->seedEvents($users);

        $this->seedFollows($users);
        $this->seedEngagement($users, $events);
        $this->seedComments($users, $events);
        $this->seedHighlightsAndStories($users);
        $this->seedRecentSearches($users);

        $iman = $users['iman_ibraah'];
        $summer = $events['summer-beach-party'];

        Ticket::firstOrCreate(
            [
                'user_id' => $iman->id,
                'event_id' => $summer->id,
            ],
            [
                'reference' => 'DAR-'.Str::upper(Str::random(10)),
                'quantity' => 2,
                'unit_price' => $summer->price,
                'total' => $summer->price * 2,
                'status' => 'confirmed',
            ]
        );
    }

    private function publishSeedImages(): void
    {
        $images = ['crowd.jpg', 'crowd-bg.jpg'];
        File::ensureDirectoryExists(storage_path('app/public/events'));

        foreach ($images as $image) {
            $source = database_path('seeders/assets/'.$image);
            $destination = storage_path('app/public/events/'.$image);

            if (File::exists($source) && ! File::exists($destination)) {
                File::copy($source, $destination);
            }
        }
    }

    /**
     * @return array<string, User>
     */
    private function seedUsers(): array
    {
        $definitions = [
            'iman_ibraah' => [
                'name' => 'Iman Ibraah',
                'email' => 'iman@darevent.app',
                'category' => 'Digital Creator',
                'bio' => "🦁 Roar before they bark\n💯 Real over fake\n🚦 Green light lifestyle",
                'country' => 'Tanzania',
            ],
            'dj_khaled' => [
                'name' => 'DJ Khaled',
                'email' => 'djkhaled@darevent.app',
                'category' => 'Event Organizer',
                'bio' => 'We the best music. Beach parties and festival nights.',
                'country' => 'United States',
                'verified' => true,
            ],
            'eventpro' => [
                'name' => 'EventPro',
                'email' => 'eventpro@darevent.app',
                'category' => 'Event Organizer',
                'bio' => 'Premium event production and live experiences.',
                'country' => 'United States',
                'verified' => true,
            ],
            'chef_marco' => [
                'name' => 'Chef Marco',
                'email' => 'chefmarco@darevent.app',
                'category' => 'Business',
                'bio' => 'Food festivals, wine pairings and good company.',
                'country' => 'Italy',
            ],
            'laughfactory' => [
                'name' => 'LaughFactory',
                'email' => 'laughfactory@darevent.app',
                'category' => 'Artist',
                'bio' => 'Stand-up comedy every week.',
                'country' => 'United States',
                'verified' => true,
            ],
            'burna_nation' => [
                'name' => 'Burna Nation',
                'email' => 'burnanation@darevent.app',
                'category' => 'Event Organizer',
                'bio' => 'Afrobeats tours and culture nights.',
                'country' => 'Nigeria',
                'verified' => true,
            ],
            'student_union' => [
                'name' => 'Student Union',
                'email' => 'studentunion@darevent.app',
                'category' => 'Education',
                'bio' => 'Campus events, freshers fairs and student life.',
                'country' => 'United States',
            ],
            'mit_harvard' => [
                'name' => 'MIT & Harvard',
                'email' => 'mh@darevent.app',
                'category' => 'Education',
                'bio' => 'Inter-college hackathons and innovation events.',
                'country' => 'United States',
                'verified' => true,
            ],
            'greek_life' => [
                'name' => 'Greek Life',
                'email' => 'greeklife@darevent.app',
                'category' => 'Event Organizer',
                'bio' => 'Homecoming, tailgates and afterparties.',
                'country' => 'United States',
            ],
            'zara_m' => [
                'name' => 'Zara M.',
                'email' => 'zara@darevent.app',
                'category' => 'Personal',
                'bio' => 'Rooftop parties and glow nights.',
                'country' => 'United States',
            ],
            'the_johnsons' => [
                'name' => 'The Johnsons',
                'email' => 'johnsons@darevent.app',
                'category' => 'Personal',
                'bio' => 'Celebrating every golden milestone.',
                'country' => 'United States',
            ],
            'funworld_events' => [
                'name' => 'FunWorld Events',
                'email' => 'funworld@darevent.app',
                'category' => 'Event Organizer',
                'bio' => 'Family fun fairs and kids celebrations.',
                'country' => 'United States',
            ],
            'luxury_nuptials' => [
                'name' => 'Luxury Nuptials',
                'email' => 'luxurynuptials@darevent.app',
                'category' => 'Event Organizer',
                'bio' => 'Destination weddings by the sea.',
                'country' => 'United States',
                'verified' => true,
            ],
            'elena_marcus' => [
                'name' => 'Elena & Marcus',
                'email' => 'elena@darevent.app',
                'category' => 'Personal',
                'bio' => 'Our wedding journey and garden receptions.',
                'country' => 'United States',
            ],
            'coastal_weddings' => [
                'name' => 'Coastal Weddings',
                'email' => 'coastal@darevent.app',
                'category' => 'Business',
                'bio' => 'Sunset ceremonies on the coast.',
                'country' => 'United States',
            ],
            'varsity_athletics' => [
                'name' => 'Varsity Athletics',
                'email' => 'varsity@darevent.app',
                'category' => 'Education',
                'bio' => 'Game days, tailgates and campus sport.',
                'country' => 'United States',
            ],
            'sweet16_pro' => [
                'name' => 'Sweet 16 Pro',
                'email' => 'sweet16@darevent.app',
                'category' => 'Event Organizer',
                'bio' => 'Glam celebrations for milestone birthdays.',
                'country' => 'United States',
            ],
            'sarah_m' => [
                'name' => 'Sarah M.',
                'email' => 'sarah@darevent.app',
                'category' => 'Personal',
                'bio' => 'Always chasing the next good night.',
                'country' => 'United States',
            ],
            'mike_events' => [
                'name' => 'Mike Events',
                'email' => 'mike@darevent.app',
                'category' => 'Digital Creator',
                'bio' => 'Event photographer and festival regular.',
                'country' => 'United States',
            ],
            'lisa_d' => [
                'name' => 'Lisa D.',
                'email' => 'lisa@darevent.app',
                'category' => 'Artist',
                'bio' => 'Music, art and good energy.',
                'country' => 'United States',
            ],
            'foodie_jane' => [
                'name' => 'Foodie Jane',
                'email' => 'jane@darevent.app',
                'category' => 'Digital Creator',
                'bio' => 'Eating my way through every festival.',
                'country' => 'United States',
            ],
            'nyc_dave' => [
                'name' => 'NYC Dave',
                'email' => 'dave@darevent.app',
                'category' => 'Business',
                'bio' => 'New York events and city nights.',
                'country' => 'United States',
            ],
        ];

        $users = [];

        foreach ($definitions as $username => $data) {
            $users[$username] = User::updateOrCreate(
                ['username' => $username],
                array_merge([
                    'password' => 'password',
                    'gender' => null,
                    'verified' => false,
                    'verified_at' => null,
                ], $data, [
                    'username' => $username,
                    'verified' => $data['verified'] ?? false,
                    'verified_at' => ($data['verified'] ?? false) ? now() : null,
                ])
            );
        }

        return $users;
    }

    /**
     * @param  array<string, User>  $users
     * @return array<string, Event>
     */
    private function seedEvents(array $users): array
    {
        $definitions = [
            [
                'title' => 'Summer Beach Party',
                'host' => 'dj_khaled',
                'category' => 'music',
                'location' => 'Miami Beach, FL',
                'venue' => 'Miami Beach Boardwalk',
                'description' => 'Sunset sets, ocean breeze and the best beach party of the summer.',
                'days' => 35,
                'time' => '20:00',
                'price' => 45,
                'has_video' => true,
                'image' => 'crowd.jpg',
            ],
            [
                'title' => 'Neon Night Festival',
                'host' => 'eventpro',
                'category' => 'music',
                'location' => 'Downtown LA',
                'venue' => 'Neon Warehouse',
                'description' => 'A full night of neon lights, live DJs and immersive stage design.',
                'days' => 48,
                'time' => '21:00',
                'price' => 60,
                'has_video' => false,
                'image' => 'crowd.jpg',
            ],
            [
                'title' => 'AfroBeats Live Tour',
                'host' => 'burna_nation',
                'category' => 'music',
                'location' => 'Brooklyn, NY',
                'venue' => 'Brooklyn Steel',
                'description' => 'The biggest Afrobeats tour of the year lands in Brooklyn.',
                'days' => 56,
                'time' => '19:30',
                'price' => 55,
                'has_video' => true,
                'image' => 'crowd-bg.jpg',
            ],
            [
                'title' => 'Campus Freshers Bash',
                'host' => 'student_union',
                'category' => 'university',
                'location' => 'Univ Quad Arena',
                'venue' => 'Quad Arena',
                'description' => 'Welcome week closes with a campus-wide party for new students.',
                'days' => 80,
                'time' => '18:00',
                'price' => 0,
                'has_video' => false,
                'image' => 'crowd.jpg',
            ],
            [
                'title' => 'Inter-College Hackathon',
                'host' => 'mit_harvard',
                'category' => 'university',
                'location' => 'Tech Hub Center',
                'venue' => 'Tech Hub Center',
                'description' => '48 hours, 200 students and one mission: build something remarkable.',
                'days' => 88,
                'time' => '09:00',
                'price' => 10,
                'has_video' => false,
                'image' => 'crowd-bg.jpg',
            ],
            [
                'title' => 'Homecoming Afterparty',
                'host' => 'greek_life',
                'category' => 'university',
                'location' => 'State Stadium',
                'venue' => 'State Stadium',
                'description' => 'The game ends, the night begins. Homecoming afterparty returns.',
                'days' => 110,
                'time' => '22:00',
                'price' => 25,
                'has_video' => true,
                'image' => 'crowd.jpg',
            ],
            [
                'title' => 'Zara’s 21st Rooftop Glow',
                'host' => 'zara_m',
                'category' => 'birthday',
                'location' => 'Sky Lounge, NYC',
                'venue' => 'Sky Lounge',
                'description' => 'A glowing rooftop celebration for Zara’s 21st birthday.',
                'days' => 63,
                'time' => '20:30',
                'price' => 0,
                'has_video' => false,
                'image' => 'crowd-bg.jpg',
            ],
            [
                'title' => 'Golden Jubilee Gala 50th',
                'host' => 'the_johnsons',
                'category' => 'birthday',
                'location' => 'Grand Ballroom',
                'venue' => 'Grand Ballroom',
                'description' => 'Fifty years of memories celebrated with dinner, speeches and dancing.',
                'days' => 71,
                'time' => '18:00',
                'price' => 80,
                'has_video' => false,
                'image' => 'crowd.jpg',
            ],
            [
                'title' => 'Neon Kids Fun Fair',
                'host' => 'funworld_events',
                'category' => 'birthday',
                'location' => 'Central Park Playzone',
                'venue' => 'Central Park Playzone',
                'description' => 'Rides, games and glow-in-the-dark fun for the whole family.',
                'days' => 84,
                'time' => '13:00',
                'price' => 15,
                'has_video' => false,
                'image' => 'crowd.jpg',
            ],
            [
                'title' => 'Amani & Liam Beach Wedding',
                'host' => 'luxury_nuptials',
                'category' => 'weddings',
                'location' => 'Key West Resort',
                'venue' => 'Key West Resort',
                'description' => 'An intimate beachfront ceremony as the sun sets over Key West.',
                'days' => 42,
                'time' => '16:00',
                'price' => 0,
                'has_video' => true,
                'image' => 'crowd.jpg',
            ],
            [
                'title' => 'Royal Garden Reception',
                'host' => 'elena_marcus',
                'category' => 'weddings',
                'location' => 'Botanical Gardens',
                'venue' => 'Botanical Gardens',
                'description' => 'Elena and Marcus invite you to a garden reception under the stars.',
                'days' => 77,
                'time' => '17:30',
                'price' => 0,
                'has_video' => false,
                'image' => 'crowd-bg.jpg',
            ],
            [
                'title' => 'Boho Sunset Ceremony',
                'host' => 'coastal_weddings',
                'category' => 'weddings',
                'location' => 'Malibu Bluffs',
                'venue' => 'Malibu Bluffs',
                'description' => 'A boho ceremony on the cliffs with a sunset vow exchange.',
                'days' => 92,
                'time' => '17:00',
                'price' => 0,
                'has_video' => false,
                'image' => 'crowd.jpg',
            ],
            [
                'title' => 'Food & Wine Carnival',
                'host' => 'chef_marco',
                'category' => 'music',
                'location' => 'Central Park, NY',
                'venue' => 'Central Park Great Lawn',
                'description' => 'Chef Marco brings together the best food and wine under the open sky.',
                'days' => 57,
                'time' => '14:00',
                'price' => 35,
                'has_video' => false,
                'image' => 'crowd.jpg',
            ],
            [
                'title' => 'Varsity Football Tailgate',
                'host' => 'varsity_athletics',
                'category' => 'university',
                'location' => 'Campus South Lot',
                'venue' => 'Campus South Lot',
                'description' => 'Game day food, music and school spirit before kickoff.',
                'days' => 118,
                'time' => '12:00',
                'price' => 20,
                'has_video' => true,
                'image' => 'crowd-bg.jpg',
            ],
            [
                'title' => 'Sweet 16 Glam Night',
                'host' => 'sweet16_pro',
                'category' => 'birthday',
                'location' => 'Crystal Ballroom',
                'venue' => 'Crystal Ballroom',
                'description' => 'A glamorous Sweet 16 with dinner, dancing and a photo wall.',
                'days' => 137,
                'time' => '19:00',
                'price' => 30,
                'has_video' => false,
                'image' => 'crowd.jpg',
            ],
            [
                'title' => 'Live Comedy Night',
                'host' => 'laughfactory',
                'category' => 'other',
                'location' => 'Chicago Theater',
                'venue' => 'Chicago Theater',
                'description' => 'A stacked line-up of stand-up comedians for one night only.',
                'days' => 28,
                'time' => '19:30',
                'price' => 40,
                'has_video' => false,
                'image' => 'crowd.jpg',
            ],
        ];

        $events = [];

        foreach ($definitions as $definition) {
            $slug = Str::slug($definition['title']);
            $startsAt = Carbon::now()
                ->addDays($definition['days'])
                ->setTimeFromTimeString($definition['time']);

            $events[$slug] = Event::updateOrCreate(
                ['slug' => $slug],
                [
                    'user_id' => $users[$definition['host']]->id,
                    'title' => $definition['title'],
                    'description' => $definition['description'],
                    'category' => $definition['category'],
                    'location' => $definition['location'],
                    'venue' => $definition['venue'],
                    'starts_at' => $startsAt,
                    'ends_at' => $startsAt->copy()->addHours(4),
                    'price' => $definition['price'],
                    'currency' => 'USD',
                    'image_path' => 'events/'.$definition['image'],
                    'has_video' => $definition['has_video'],
                    'is_public' => true,
                ]
            );
        }

        return $events;
    }

    /**
     * @param  array<string, User>  $users
     */
    private function seedFollows(array $users): void
    {
        $iman = $users['iman_ibraah'];

        $iman->following()->syncWithoutDetaching([
            $users['dj_khaled']->id,
            $users['eventpro']->id,
            $users['chef_marco']->id,
            $users['laughfactory']->id,
            $users['burna_nation']->id,
            $users['luxury_nuptials']->id,
        ]);

        $users['dj_khaled']->following()->syncWithoutDetaching([$iman->id]);
        $users['eventpro']->following()->syncWithoutDetaching([$iman->id]);
        $users['chef_marco']->following()->syncWithoutDetaching([$iman->id]);
        $users['sarah_m']->following()->syncWithoutDetaching([$iman->id]);
        $users['mike_events']->following()->syncWithoutDetaching([$iman->id]);
    }

    /**
     * @param  array<string, User>  $users
     * @param  array<string, Event>  $events
     */
    private function seedEngagement(array $users, array $events): void
    {
        $likerPool = collect($users)->except(['iman_ibraah'])->values();

        foreach ($events as $event) {
            $eligible = $likerPool
                ->reject(fn (User $user) => $user->id === $event->user_id)
                ->values();

            $offset = $event->id % max($eligible->count(), 1);
            $ordered = $eligible->slice($offset)->merge($eligible->take($offset));

            $event->likes()->syncWithoutDetaching(
                $ordered->take(4 + ($event->id % 4))->pluck('id')
            );

            $event->savedBy()->syncWithoutDetaching(
                $ordered->reverse()->take(1 + ($event->id % 3))->pluck('id')
            );

            $event->update([
                'likes_count' => $event->likes()->count(),
                'saves_count' => $event->savedBy()->count(),
            ]);
        }
    }

    /**
     * @param  array<string, User>  $users
     * @param  array<string, Event>  $events
     */
    private function seedComments(array $users, array $events): void
    {
        $seedComments = [
            'summer-beach-party' => [
                ['sarah_m', 'Cant wait for this! 🔥', [
                    ['mike_events', 'Same here, see you on the sand!', null],
                ]],
                ['mike_events', 'Got my tickets already', [
                    ['dj_khaled', 'See you there Mike! 🙌', null],
                ]],
            ],
            'neon-night-festival' => [
                ['lisa_d', 'Best festival of the year!', []],
            ],
            'food-wine-carnival' => [
                ['foodie_jane', 'The wine selection was amazing last year 🍷', [
                    ['chef_marco', 'This year will be even better!', null],
                ]],
                ['nyc_dave', 'Bringing the whole family', []],
            ],
        ];

        foreach ($seedComments as $slug => $threads) {
            $event = $events[$slug];

            if ($event->comments()->exists()) {
                continue;
            }

            foreach ($threads as [$username, $body, $replies]) {
                $comment = Comment::create([
                    'event_id' => $event->id,
                    'user_id' => $users[$username]->id,
                    'body' => $body,
                ]);

                foreach ($replies as [$replyUsername, $replyBody]) {
                    Comment::create([
                        'event_id' => $event->id,
                        'user_id' => $users[$replyUsername]->id,
                        'parent_id' => $comment->id,
                        'body' => $replyBody,
                    ]);
                }
            }

            $event->update(['comments_count' => $event->comments()->count()]);
        }
    }

    /**
     * @param  array<string, User>  $users
     */
    private function seedHighlightsAndStories(array $users): void
    {
        $highlights = [
            ['Events', 'calendar'],
            ['Parties', 'musical-notes'],
            ['Trips', 'airplane'],
            ['Food', 'restaurant'],
            ['New', 'add-circle'],
        ];

        foreach ($highlights as $position => [$label, $icon]) {
            StoryHighlight::firstOrCreate(
                ['user_id' => $users['iman_ibraah']->id, 'label' => $label],
                ['icon' => $icon, 'position' => $position]
            );
        }

        $storyOwners = ['dj_khaled', 'eventpro', 'chef_marco', 'laughfactory', 'burna_nation'];

        foreach ($storyOwners as $index => $username) {
            Story::firstOrCreate(
                [
                    'user_id' => $users[$username]->id,
                    'caption' => 'Live from '.$users[$username]->name,
                ],
                [
                    'is_live' => $index === 0,
                    'expires_at' => now()->addDay(),
                ]
            );
        }
    }

    /**
     * @param  array<string, User>  $users
     */
    private function seedRecentSearches(array $users): void
    {
        $searches = [
            ['DJ Khaled', 'host', 'Organizer'],
            ['Summer Beach Party', 'event', 'Event · Miami Beach, FL'],
            ['AfroBeats Live Tour', 'music', 'Music Event · Brooklyn, NY'],
            ['Campus Freshers Bash', 'university', 'University · Quad Arena'],
            ['Elena & Marcus', 'host', 'Wedding Host'],
        ];

        foreach ($searches as [$query, $type, $subtitle]) {
            RecentSearch::firstOrCreate(
                ['user_id' => $users['iman_ibraah']->id, 'query' => $query],
                ['type' => $type, 'subtitle' => $subtitle]
            );
        }
    }
}
