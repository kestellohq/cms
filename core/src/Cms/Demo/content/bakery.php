<?php

declare(strict_types=1);

/*
 * The public demo's sample site: Larkspur Bakery, a small bakery on an
 * invented quay in an invented town. DemoContent::seed() reads this file;
 * it holds the words and the shape of the site, the class does the work.
 *
 * Blocks are stored as in a page's "body" field: {type, props, children}.
 * An image prop written "@image:<key>" becomes the id of the generated
 * image <key> (see "images"). Without GD there are no images: a cover keeps
 * its colour, an image block is left out, and media and text keeps its text.
 * Links in menus name pages and terms by their "key" here.
 */

$b = static fn(string $type, array $props = [], array $children = []): array => ['type' => $type, 'props' => $props, 'children' => $children];
$heading = static fn(string $text, string $level = '2'): array => $b('core/heading', ['text' => $text, 'level' => $level]);
$text = static fn(string $html): array => $b('core/text', ['text' => $html]);
$button = static fn(string $label, string $link, string $variant = 'primary'): array => $b('core/button', ['text' => $label, 'link' => $link, 'variant' => $variant]);

return [
    'settings' => [
        'general' => [
            'site_name' => 'Larkspur Bakery',
            'site_description' => 'Sourdough, rye and sweet buns, baked on Fennel Quay every morning.',
        ],
        'reading' => [
            'discourage_search' => '1',
        ],
    ],

    'front_page' => 'home',

    /*
     * Flat shapes on a 960 by 640 canvas, in warm bakery colours. Shapes:
     * rect [x1, y1, x2, y2], ellipse [cx, cy, width, height],
     * poly [x, y, x, y, ...], line [x1, y1, x2, y2, thickness].
     */
    'images' => [
        'loaves' => [
            'filename' => 'larkspur-loaves.jpg',
            'alt' => 'Three crusty loaves on a wooden shelf',
            'background' => '#f3e6cf',
            'shapes' => [
                ['rect', [0, 470, 960, 640], '#b98a5a'],
                ['rect', [0, 470, 960, 488], '#a77a4c'],
                ['ellipse', [250, 400, 300, 170], '#b5763a'],
                ['ellipse', [500, 380, 320, 190], '#a8672f'],
                ['ellipse', [740, 405, 280, 160], '#c0843f'],
                ['line', [190, 370, 240, 330, 10], '#7d4a22'],
                ['line', [250, 385, 300, 345, 10], '#7d4a22'],
                ['line', [440, 350, 500, 305, 10], '#6f411d'],
                ['line', [510, 370, 570, 325, 10], '#6f411d'],
                ['line', [700, 380, 790, 380, 10], '#83501f'],
            ],
        ],
        'buns' => [
            'filename' => 'larkspur-buns.jpg',
            'alt' => 'A dark tray of round cinnamon buns',
            'background' => '#c46a4a',
            'shapes' => [
                ['rect', [120, 100, 840, 540], '#3d2a1e'],
                ...array_merge(...array_map(static fn(array $at): array => [
                    ['ellipse', [$at[0], $at[1], 150, 150], '#d9a75b'],
                    ['ellipse', [$at[0], $at[1], 86, 86], '#b5763a'],
                    ['ellipse', [$at[0], $at[1], 30, 30], '#e9c27f'],
                ], [[240, 210], [400, 210], [560, 210], [720, 210], [240, 430], [400, 430], [560, 430], [720, 430]])),
            ],
        ],
        'starter' => [
            'filename' => 'larkspur-starter.jpg',
            'alt' => 'A glass jar of bubbly sourdough starter',
            'background' => '#9aa77f',
            'shapes' => [
                ['rect', [0, 560, 960, 640], '#6b4a2e'],
                ['rect', [370, 190, 590, 560], '#f7f0e3'],
                ['rect', [386, 320, 574, 546], '#efe2c4'],
                ['rect', [355, 150, 605, 196], '#6b4a2e'],
                ['ellipse', [430, 360, 26, 26], '#d8c79f'],
                ['ellipse', [500, 410, 18, 18], '#d8c79f'],
                ['ellipse', [540, 350, 30, 30], '#d8c79f'],
                ['ellipse', [450, 470, 22, 22], '#d8c79f'],
                ['ellipse', [530, 500, 16, 16], '#d8c79f'],
            ],
        ],
        'soda' => [
            'filename' => 'larkspur-soda-bread.jpg',
            'alt' => 'A round soda bread with a cross cut on a wooden board',
            'background' => '#3d2a1e',
            'shapes' => [
                ['ellipse', [480, 330, 560, 560], '#b98a5a'],
                ['ellipse', [480, 320, 380, 380], '#c58a46'],
                ['line', [370, 320, 590, 320, 16], '#8a5226'],
                ['line', [480, 210, 480, 430, 16], '#8a5226'],
                ['ellipse', [400, 250, 10, 10], '#f3e6cf'],
                ['ellipse', [560, 400, 12, 12], '#f3e6cf'],
                ['ellipse', [430, 410, 8, 8], '#f3e6cf'],
            ],
        ],
        'quay' => [
            'filename' => 'larkspur-on-the-quay.jpg',
            'alt' => 'A small bakery with a red roof under a low morning sun',
            'background' => '#f6d9a8',
            'shapes' => [
                ['ellipse', [700, 250, 220, 220], '#f2b35b'],
                ['poly', [0, 470, 260, 380, 520, 450, 780, 360, 960, 420, 960, 640, 0, 640], '#d9a75b'],
                ['rect', [0, 520, 960, 640], '#8a5226'],
                ['rect', [180, 300, 420, 520], '#fbf6ec'],
                ['poly', [160, 305, 300, 205, 440, 305], '#c46a4a'],
                ['rect', [275, 410, 325, 520], '#6b4a2e'],
                ['rect', [200, 345, 255, 395], '#9aa77f'],
                ['rect', [345, 345, 400, 395], '#9aa77f'],
            ],
        ],
    ],

    'terms' => [
        'category' => ['News', 'Recipes'],
        'tag' => ['Sourdough', 'Rye', 'Seasonal', 'Opening hours', 'Sweet things'],
    ],

    'form' => [
        'title' => 'Contact',
        'definition' => [
            'submit_label' => 'Send',
            'success_message' => 'Thank you. We read every message and reply within a day or two, usually before the afternoon bake.',
            'fields' => [
                ['name' => 'name', 'type' => 'text', 'label' => 'Your name', 'required' => true, 'options' => ['max' => 100]],
                ['name' => 'email', 'type' => 'email', 'label' => 'Email', 'required' => true],
                ['name' => 'message', 'type' => 'textarea', 'label' => 'Message', 'required' => true],
            ],
        ],
    ],

    // in this order; the theme has its own /about, so About lives at /our-story
    'pages' => [
        'home' => [
            'title' => 'Home',
            'slug' => 'home',
            'blocks' => [
                $b('core/cover', ['image' => '@image:loaves', 'shade' => 'dark', 'height' => 'medium'], [
                    $heading('Bread from the oven on Fennel Quay'),
                    $text('<p>Sourdough, rye and sweet buns, baked in small batches every morning from seven.</p>'),
                    $b('core/buttons', ['align' => 'center'], [
                        $button("See what we're baking", '/blog'),
                        $button('Find us', '/visit-us', 'secondary'),
                    ]),
                ]),
                $b('core/columns', ['layout' => '1-1-1', 'gap' => 'lg', 'width' => 'wide'], [
                    $b('core/column', [], [
                        $heading('Every morning', '3'),
                        $text('<p>The first loaves come out at half past six. By the time we open the door, the shelves are full and the windows have steamed up.</p>'),
                    ]),
                    $b('core/column', [], [
                        $heading('Slow dough', '3'),
                        $text('<p>Our sourdough rests for a day and a night before it is baked. It takes longer, and you can taste the difference.</p>'),
                    ]),
                    $b('core/column', [], [
                        $heading('Weekend orders', '3'),
                        $text('<p>Tell us by Thursday what you would like, and it will be waiting for you on Saturday morning with your name on it.</p>'),
                    ]),
                ]),
                $b('core/media-text', ['image' => '@image:buns', 'side' => 'start'], [
                    $heading('Made by hand, in small batches'),
                    $text('<p>There are four of us, one big oven and a long wooden table. Everything in the case was shaped by one of us that morning, and when a tray is gone, it is gone until tomorrow.</p>'),
                    $button('Read our story', '/our-story', 'secondary'),
                ]),
                $heading("What's on the shelves"),
                $b('core/list', ['items' => [
                    ['text' => 'Country sourdough, white and wholemeal'],
                    ['text' => 'Dark rye with sunflower seeds'],
                    ['text' => 'Cinnamon and cardamom buns'],
                    ['text' => 'Soda bread on Sundays'],
                ]]),
                $b('core/image', ['image' => '@image:soda', 'caption' => 'Sunday soda bread, cut and floured before it goes in.', 'width' => 'wide', 'ratio' => '3/2']),
                $b('core/group', ['tag' => 'section', 'width' => 'wide', 'tone' => 'inverse'], [
                    $heading('Order for the weekend'),
                    $text('<p>Send us a note by Thursday evening and your loaves are waiting on Saturday morning.</p>'),
                    $button('Place an order', '/contact'),
                ]),
            ],
        ],
        'about' => [
            'title' => 'About',
            'slug' => 'our-story',
            'blocks' => [
                $text('<p>Larkspur Bakery opened in an old net store on Fennel Quay in the spring of 2019. Before that, it was a kitchen table, a borrowed oven and a lot of loaves given away to neighbours.</p>'),
                $b('core/media-text', ['image' => '@image:quay', 'side' => 'end'], [
                    $heading('Who we are'),
                    $text('<p>Margit bakes the bread. Tom does the pastry and most of the talking. Ines and Sam run the counter and know what half the town has for breakfast.</p>'),
                ]),
                $heading('How we bake'),
                $text('<p>We use flour from a mill two valleys over, water from the tap and salt from the coast. Our starter is older than the shop. Nothing goes in the dough that you would not find in your own kitchen.</p><p>Bread that is left at the end of the day goes to the community fridge by the harbour office, or into tomorrow\'s bread pudding.</p>'),
                $heading('Come and say hello'),
                $text('<p>The best way to know us is to come in on a Saturday morning, when the queue goes past the window and someone always ends up talking about the weather.</p>'),
                $button('How to find us', '/visit-us'),
            ],
        ],
        'visit' => [
            'title' => 'Visit us',
            'slug' => 'visit-us',
            'blocks' => [
                $text('<p>We are at the end of Fennel Quay, next to the harbour office. There is a bench outside if the weather is kind.</p>'),
                $b('core/columns', ['layout' => '1-1', 'gap' => 'lg', 'width' => 'wide'], [
                    $b('core/column', [], [
                        $heading('Opening hours', '3'),
                        $b('core/list', ['items' => [
                            ['text' => 'Tuesday to Friday: 7:00 to 16:00'],
                            ['text' => 'Saturday: 7:00 to 14:00'],
                            ['text' => 'Sunday: 8:00 to 12:00'],
                            ['text' => 'Monday: closed'],
                        ]]),
                    ]),
                    $b('core/column', [], [
                        $heading('Address', '3'),
                        $text('<p>Larkspur Bakery<br>7 Fennel Quay<br>Larkmouth LM4 2QB</p><p>Phone: 01632 960418<br>Email: hello@larkspur.example</p>'),
                    ]),
                ]),
                $heading('Good to know'),
                $b('core/details', ['summary' => 'Can I bring my dog?', 'text' => '<p>Yes. There is a water bowl by the door, and we keep a jar of dog biscuits behind the counter.</p>']),
                $b('core/details', ['summary' => 'Is there parking nearby?', 'text' => '<p>The harbour car park is two minutes away on foot. It is free before ten on weekdays.</p>']),
                $b('core/details', ['summary' => 'Do you bake anything without wheat?', 'text' => '<p>Our dark rye has no wheat in it, though it is baked in the same kitchen as everything else.</p>']),
            ],
        ],
        'contact' => [
            'title' => 'Contact',
            'slug' => 'contact',
            'blocks' => [
                $text('<p>Questions, weekend orders, a cake for a birthday: send us a note and one of us will write back, usually within a day.</p>'),
                $b('core/form', ['form' => '@form']),
                $text('<p>You can also call us on 01632 960418 while the shop is open, or come by and ask at the counter.</p>'),
            ],
        ],
    ],

    'posts' => [
        [
            'title' => 'Our spring rye is back',
            'slug' => 'our-spring-rye-is-back',
            'days_ago' => 3,
            'image' => 'loaves',
            'category' => 'News',
            'tags' => ['Rye', 'Seasonal'],
            'excerpt' => 'The dark rye with sunflower seeds is on the shelves again, from Tuesday to Saturday.',
            'blocks' => [
                $text('<p>Every winter we take a break from our seeded rye, mostly because the oven is busy with other things. This week it came back, and the first tray was gone before nine.</p>'),
                $text('<p>It is a dense, moist loaf that keeps for most of a week. We like it sliced thin with butter and a little salt, or with smoked fish from the stall across the quay.</p>'),
                $text('<p>You will find it on the shelves from Tuesday to Saturday. If you want to be sure of one, add it to your weekend order.</p>'),
            ],
        ],
        [
            'title' => 'How we feed our sourdough starter',
            'slug' => 'how-we-feed-our-sourdough-starter',
            'days_ago' => 9,
            'image' => 'starter',
            'category' => 'Recipes',
            'tags' => ['Sourdough'],
            'excerpt' => 'Flour, water and a warm corner: the routine that keeps our starter lively.',
            'blocks' => [
                $text('<p>People often ask how we look after the starter that goes into every sourdough loaf. The answer is less mysterious than you might think.</p>'),
                $heading('Our routine'),
                $b('core/list', ['ordered' => true, 'items' => [
                    ['text' => 'Keep 50 grams of the old starter and discard the rest.'],
                    ['text' => 'Add 50 grams of flour and 50 grams of lukewarm water.'],
                    ['text' => 'Stir well, cover loosely and leave it somewhere warm.'],
                    ['text' => 'Use it when it has doubled and smells pleasantly sour.'],
                ]]),
                $text('<p>At home, once a day is plenty. If you are going away, keep it in the fridge and give it two feeds before you bake with it again.</p>'),
            ],
        ],
        [
            'title' => 'Soda bread for a quiet Sunday',
            'slug' => 'soda-bread-for-a-quiet-sunday',
            'days_ago' => 16,
            'image' => 'soda',
            'category' => 'Recipes',
            'tags' => ['Seasonal'],
            'excerpt' => 'No yeast and no waiting: a round loaf you can have on the table in under an hour.',
            'blocks' => [
                $text('<p>Soda bread is the loaf we make when there is no time to wait for dough to rise. It needs no yeast and no kneading, and it is ready in under an hour.</p>'),
                $heading('What you need'),
                $b('core/list', ['items' => [
                    ['text' => '450 grams of plain flour, or half wholemeal'],
                    ['text' => '1 teaspoon of bicarbonate of soda'],
                    ['text' => '1 teaspoon of salt'],
                    ['text' => '400 millilitres of buttermilk'],
                ]]),
                $text('<p>Mix the dry things, pour in the buttermilk and bring it together with your hand. Shape a round, cut a deep cross on top and bake at 200 degrees for about 35 minutes, until it sounds hollow when you knock on the bottom.</p>'),
            ],
        ],
        [
            'title' => 'New Saturday hours',
            'slug' => 'new-saturday-hours',
            'days_ago' => 23,
            'image' => 'quay',
            'category' => 'News',
            'tags' => ['Opening hours'],
            'excerpt' => 'From next month we open at seven on Saturdays and close at two.',
            'blocks' => [
                $text('<p>Saturday mornings have become our busiest time of the week, and the queue now often starts before we open. So from next month we will open the door at seven instead of eight.</p>'),
                $text('<p>We will close a little earlier, at two, so that the four of us still get a proper weekend. Weekend orders can be collected any time between seven and two.</p>'),
                $text('<p>Thank you for all the early starts. We will have the coffee ready.</p>'),
            ],
        ],
        [
            'title' => 'Cinnamon buns, the way Margit makes them',
            'slug' => 'cinnamon-buns-the-way-margit-makes-them',
            'days_ago' => 30,
            'image' => 'buns',
            'category' => 'Recipes',
            'tags' => ['Sweet things'],
            'excerpt' => 'A soft dough, plenty of butter and a pinch of cardamom.',
            'blocks' => [
                $text('<p>These are the buns that sell out first on a Saturday. Margit learned them from her grandmother, who put cardamom in nearly everything.</p>'),
                $text('<p>The dough is enriched with milk and butter and left to rise slowly. We roll it out, spread it with butter, brown sugar and cinnamon, then roll it up, slice it and tie each piece into a knot.</p>'),
                $text('<p>Bake them until they are deep golden, brush them with a little syrup and leave them to cool, if you can. They are best on the day, and still good warmed up the next morning.</p>'),
            ],
        ],
    ],

    /*
     * Items: ['page', key], ['term', taxonomy, name], ['archive', type, label].
     * Each menu goes to its theme location when the active theme has one.
     */
    'menus' => [
        ['name' => 'Main menu', 'location' => 'primary', 'items' => [
            ['page', 'home'],
            ['page', 'about'],
            ['page', 'visit'],
            ['archive', 'post', 'Blog'],
            ['page', 'contact'],
        ]],
        ['name' => 'Footer menu', 'location' => 'footer', 'items' => [
            ['page', 'visit'],
            ['term', 'category', 'Recipes'],
            ['page', 'contact'],
        ]],
    ],
];
