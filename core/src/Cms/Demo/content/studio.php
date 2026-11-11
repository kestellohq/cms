<?php

declare(strict_types=1);

// Content in its own language, not interface text: i18n:extract skips this file.

/*
 * The public demo's sample site: Taller Arroyo & Lind, an invented
 * architecture studio in Valencia, in English and Spanish, hung in the
 * Exhibit theme. DemoContent reads this file; it holds the words and the
 * shape of the site, the class does the work.
 *
 * The studio, its people, projects and talks are invented. The photographs
 * are real and free to reuse (CC0 or public domain, credited in each
 * caption), and none shows a building presented as the studio's own that
 * someone could recognise: the journal's places (Urbino, a model of
 * Chochikukyo, a drawing by Vanvitelli) are written about as visits.
 *
 * Blocks are stored as in a "body" field: {type, props, children}. "@photo:<key>"
 * in a prop or a field becomes the id of that photo, "@form" the id of the
 * entry's language's form. Each entry, page and term has its Spanish
 * translation under "es"; fields not listed there are shared or copied.
 */

$b = static fn(string $type, array $props = [], array $children = []): array => ['type' => $type, 'props' => $props, 'children' => $children];
$heading = static fn(string $text, string $level = '2'): array => $b('core/heading', ['text' => $text, 'level' => $level]);
$text = static fn(string $html): array => $b('core/text', ['text' => $html]);
$quote = static fn(string $text): array => $b('core/quote', ['text' => $text]);

return [
    'theme' => 'exhibit',

    'settings' => [
        'general' => [
            'site_name' => 'Taller Arroyo & Lind',
            'site_description' => 'Houses, schools and rooms for a town, drawn and built from Valencia.',
            'languages' => 'es',
            'site_translations' => json_encode(['es' => [
                'site_name' => 'Taller Arroyo & Lind',
                'site_description' => 'Casas, escuelas y salas para una ciudad, dibujadas y construidas desde Valencia.',
            ]], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'path_translations' => json_encode(['es' => [
                '/projects' => '/proyectos',
                '/talks' => '/charlas',
                '/team' => '/equipo',
                '/blog' => '/diario',
                '/category' => '/categoria',
            ]], JSON_THROW_ON_ERROR),
        ],
        'reading' => [
            'discourage_search' => '1',
        ],
    ],

    /*
     * Content types built in the admin (Content → Content types), so the demo
     * shows how one is made. Their fields fill the theme's label plates.
     */
    'types' => [
        'projects' => [
            'singular' => 'Project', 'plural' => 'Projects', 'icon' => 'buildings', 'path' => '/projects', 'archive' => true,
            'description' => 'Built work, work on site and studies, newest first.',
            'fields' => [
                ['name' => 'number', 'type' => 'number', 'label' => 'Catalogue number', 'indexed' => true, 'width' => 'sidebar', 'options' => ['integer' => true, 'min' => 1]],
                ['name' => 'place', 'type' => 'text', 'indexed' => true, 'width' => 'half', 'translatable' => false],
                ['name' => 'year', 'type' => 'number', 'indexed' => true, 'width' => 'half', 'options' => ['integer' => true]],
                ['name' => 'use', 'type' => 'select', 'indexed' => true, 'width' => 'half', 'options' => ['choices' => [
                    'housing' => 'Housing', 'culture' => 'Culture', 'workplace' => 'Workplace', 'landscape' => 'Landscape', 'education' => 'Education',
                ]]],
                ['name' => 'area', 'type' => 'number', 'label' => 'Area (m²)', 'indexed' => true, 'width' => 'half', 'options' => ['integer' => true, 'min' => 0]],
                ['name' => 'status', 'type' => 'select', 'indexed' => true, 'width' => 'sidebar', 'options' => ['choices' => [
                    'built' => 'Built', 'construction' => 'Under construction', 'study' => 'Study', 'competition' => 'Competition',
                ]]],
                ['name' => 'photos', 'type' => 'media', 'options' => ['kinds' => ['image'], 'multiple' => true, 'max' => 12]],
                ['name' => 'body', 'type' => 'rich_text', 'label' => 'About the project'],
                ['name' => 'credits', 'type' => 'text', 'help' => 'Who worked on it, in one line.'],
            ],
        ],
        'talks' => [
            'singular' => 'Talk', 'plural' => 'Talks', 'icon' => 'microphone-stage', 'path' => '/talks', 'archive' => true,
            'description' => 'Evenings at the studio and elsewhere. Free; no booking unless the talk says so.',
            'fields' => [
                ['name' => 'starts', 'type' => 'datetime', 'label' => 'Starts at', 'indexed' => true, 'required' => true, 'width' => 'sidebar'],
                ['name' => 'place', 'type' => 'text', 'indexed' => true],
                ['name' => 'body', 'type' => 'rich_text', 'label' => 'About the talk'],
            ],
        ],
        'team' => [
            'singular' => 'Person', 'plural' => 'People', 'icon' => 'users-three', 'path' => '/team', 'archive' => true,
            'description' => 'The people who work at the studio.',
            'fields' => [
                ['name' => 'role', 'type' => 'text', 'indexed' => true],
                ['name' => 'body', 'type' => 'rich_text', 'label' => 'About'],
            ],
        ],
    ],

    /* Files in content/photos/: 1600 pixels on the long side, metadata removed. */
    'photos' => [
        'terraces' => [
            'file' => 'concrete-volumes.webp',
            'alt' => 'A corner of board-marked concrete against a clear blue sky',
            'caption' => 'Lance Anderson, Wikimedia Commons (CC0)',
        ],
        'chapel' => [
            'file' => 'concrete-slab.webp',
            'alt' => 'A tall concrete volume with three round windows above a band of copper panels',
            'caption' => 'Samuel Zeller, Wikimedia Commons (CC0)',
        ],
        'galleries' => [
            'file' => 'concrete-gallery.webp',
            'alt' => 'A long concrete gallery of curved fins beside a planted courtyard',
            'caption' => 'Lekeboy54, Wikimedia Commons (CC0)',
        ],
        'flats' => [
            'file' => 'brick-shadow.webp',
            'alt' => 'The shadow of a bare tree on a red brick front with arched windows',
            'caption' => 'Pecid2180, Wikimedia Commons (CC0)',
        ],
        'stands' => [
            'file' => 'metal-stair.webp',
            'alt' => 'Tiled steps between black steel handrails, down to a covered stand',
            'caption' => 'WXchaser, Wikimedia Commons (CC0)',
        ],
        'urbino' => [
            'file' => 'urbino-courtyard.webp',
            'alt' => 'A stone courtyard in Urbino with arches and tiled roofs',
            'caption' => 'Julian Lupyan, Wikimedia Commons (CC0)',
        ],
        'chochikukyo' => [
            'file' => 'chochikukyo-model.webp',
            'alt' => 'A cut-away model of the Chochikukyo house on a grass base, in an exhibition',
            'caption' => '1904.CC, Wikimedia Commons (CC0)',
        ],
        'vanvitelli' => [
            'file' => 'vanvitelli-sketch.webp',
            'alt' => 'A pencil drawing of a wall with a doorway and two smaller sketches of windows',
            'caption' => 'Luigi Vanvitelli, The Metropolitan Museum of Art (public domain)',
        ],
    ],

    /* English name => Spanish name. */
    'terms' => [
        'category' => ['Visits' => 'Visitas', 'Drawing' => 'Dibujo'],
    ],

    'forms' => [
        '' => [
            'title' => 'Start a project',
            'definition' => [
                'submit_label' => 'Send',
                'success_message' => 'Thank you. This is a demonstration site, so nobody will read it, but on a real site the studio would get it by email now.',
                'fields' => [
                    ['name' => 'name', 'type' => 'text', 'label' => 'Your name', 'required' => true, 'options' => ['max' => 100]],
                    ['name' => 'email', 'type' => 'email', 'label' => 'Email', 'required' => true],
                    ['name' => 'kind', 'type' => 'select', 'label' => 'What would you like to build?', 'options' => ['choices' => [
                        'house' => 'A house', 'housing' => 'Housing', 'public' => 'A school or a public building', 'other' => 'Something else',
                    ]]],
                    ['name' => 'message', 'type' => 'textarea', 'label' => 'Tell us about the place and the timing', 'required' => true],
                ],
            ],
        ],
        'es' => [
            'title' => 'Empezar un proyecto',
            'definition' => [
                'submit_label' => 'Enviar',
                'success_message' => 'Gracias. Este es un sitio de demostración y nadie lo leerá, pero en un sitio real el estudio lo recibiría ahora por correo.',
                'fields' => [
                    ['name' => 'name', 'type' => 'text', 'label' => 'Tu nombre', 'required' => true, 'options' => ['max' => 100]],
                    ['name' => 'email', 'type' => 'email', 'label' => 'Correo electrónico', 'required' => true],
                    ['name' => 'kind', 'type' => 'select', 'label' => '¿Qué te gustaría construir?', 'options' => ['choices' => [
                        'house' => 'Una casa', 'housing' => 'Vivienda colectiva', 'public' => 'Una escuela o un edificio público', 'other' => 'Otra cosa',
                    ]]],
                    ['name' => 'message', 'type' => 'textarea', 'label' => 'Cuéntanos sobre el lugar y los plazos', 'required' => true],
                ],
            ],
        ],
    ],

    'pages' => [
        'studio' => [
            'title' => 'Studio',
            'slug' => 'studio',
            'description' => 'A small studio in Valencia. We draw by hand first, build models of everything, and stay on site until the last door is hung.',
            'blocks' => [
                $text('<p>Taller Arroyo &amp; Lind was started by Lucía Arroyo and Henrik Lind after ten years of working on other people\'s buildings. We work on houses, housing, schools and the rooms a town shares, mostly within a day\'s drive of Valencia.</p>'),
                $heading('How we work'),
                $text('<p>Every project begins with a visit and a drawing made on the spot. Then models: in card, in timber, at 1:50 and at 1:5 for the details people will touch. We stay on site through construction, because that is where most decisions are made.</p>'),
                $text('<p>We take on a few projects a year so that the partners draw every one of them.</p>'),
            ],
            'es' => [
                'title' => 'Estudio',
                'slug' => 'estudio',
                'description' => 'Un estudio pequeño en Valencia. Dibujamos a mano primero, hacemos maquetas de todo y seguimos en la obra hasta que se cuelga la última puerta.',
                'blocks' => [
                    $text('<p>Taller Arroyo &amp; Lind lo fundaron Lucía Arroyo y Henrik Lind después de diez años trabajando en edificios de otros. Hacemos casas, vivienda colectiva, escuelas y las salas que comparte una ciudad, casi siempre a menos de un día en coche de Valencia.</p>'),
                    $heading('Cómo trabajamos'),
                    $text('<p>Cada proyecto empieza con una visita y un dibujo hecho allí mismo. Después, maquetas: de cartón, de madera, a 1:50 y a 1:5 para los detalles que la gente tocará. Seguimos en la obra durante la construcción, porque es allí donde se toman la mayoría de las decisiones.</p>'),
                    $text('<p>Aceptamos pocos proyectos al año para que los socios dibujen cada uno de ellos.</p>'),
                ],
            ],
        ],
        'contact' => [
            'title' => 'Start a project',
            'slug' => 'contact',
            'description' => 'Tell us about the place, what you would like to build and when. We answer every message within a week.',
            'blocks' => [
                $text('<p>Tell us about the place, what you would like to build and when. We answer every message within a week, and the first visit is free within the Valencia region.</p>'),
                $b('core/form', ['form' => '@form']),
                $text('<p>Taller Arroyo &amp; Lind, Valencia. This is a demonstration site: the studio is invented, and what you send here is deleted when the demo resets, every hour.</p>'),
            ],
            'es' => [
                'title' => 'Empezar un proyecto',
                'slug' => 'contacto',
                'description' => 'Cuéntanos sobre el lugar, qué te gustaría construir y cuándo. Respondemos a cada mensaje en una semana.',
                'blocks' => [
                    $text('<p>Cuéntanos sobre el lugar, qué te gustaría construir y cuándo. Respondemos a cada mensaje en una semana, y la primera visita es gratuita en la Comunidad Valenciana.</p>'),
                    $b('core/form', ['form' => '@form']),
                    $text('<p>Taller Arroyo &amp; Lind, Valencia. Este es un sitio de demostración: el estudio es inventado y lo que envíes aquí se borra cuando la demo se restablece, cada hora.</p>'),
                ],
            ],
        ],
    ],

    /* Entries of each type, the oldest first; days_ago is when each was published. */
    'entries' => [
        'projects' => [
            [
                'title' => 'Stands for a Riverside Club', 'slug' => 'riverside-stands', 'days_ago' => 400,
                'data' => [
                    'number' => 9, 'place' => 'Valencia', 'year' => 2021, 'use' => 'landscape', 'area' => 650, 'status' => 'built',
                    'photos' => ['@photo:stands'],
                    'body' => '<p>Two low stands for a rowing and tennis club beside the old riverbed. Tiled steps double as seats; the handrails are the only steel, painted black so they disappear against the shade.</p><p>The stands were built in one summer, between seasons, on the foundations of the old ones.</p>',
                    'credits' => 'Henrik Lind, Pau Ferrer',
                ],
                'es' => [
                    'title' => 'Gradas para un club del río', 'slug' => 'gradas-del-rio',
                    'data' => [
                        'body' => '<p>Dos gradas bajas para un club de remo y tenis junto al antiguo cauce del río. Los escalones de baldosa sirven de asiento; las barandillas son el único acero, pintadas de negro para que desaparezcan contra la sombra.</p><p>Se construyeron en un solo verano, entre temporadas, sobre los cimientos de las anteriores.</p>',
                        'credits' => 'Henrik Lind, Pau Ferrer',
                    ],
                ],
            ],
            [
                'title' => 'Faculty Galleries', 'slug' => 'faculty-galleries', 'days_ago' => 260,
                'data' => [
                    'number' => 10, 'place' => 'Valencia', 'year' => 2022, 'use' => 'education', 'area' => 2400, 'status' => 'study',
                    'photos' => ['@photo:galleries'],
                    'body' => '<p>A study for reopening the covered galleries of a faculty built in the 1970s: the concrete fins stay, the infill between them goes, and the courtyard becomes the building\'s main room again.</p>',
                    'credits' => 'Lucía Arroyo, Marta Climent',
                ],
                'es' => [
                    'title' => 'Galerías de la facultad', 'slug' => 'galerias-de-la-facultad',
                    'data' => [
                        'body' => '<p>Un estudio para reabrir las galerías cubiertas de una facultad de los años setenta: las aletas de hormigón se quedan, los cerramientos entre ellas desaparecen y el patio vuelve a ser la sala principal del edificio.</p>',
                        'credits' => 'Lucía Arroyo, Marta Climent',
                    ],
                ],
            ],
            [
                'title' => 'Chapel for a School', 'slug' => 'school-chapel', 'days_ago' => 150,
                'data' => [
                    'number' => 11, 'place' => 'Castellón', 'year' => 2023, 'use' => 'education', 'area' => 180, 'status' => 'built',
                    'photos' => ['@photo:chapel'],
                    'body' => '<p>A small hall for a school\'s assemblies and concerts, cast in one pour of board-marked concrete. Three round windows light the room from high up; a band of copper marks the entrance.</p>',
                    'credits' => 'Lucía Arroyo, Henrik Lind, Marta Climent',
                ],
                'es' => [
                    'title' => 'Capilla de un colegio', 'slug' => 'capilla-de-un-colegio',
                    'data' => [
                        'body' => '<p>Una pequeña sala para las asambleas y los conciertos de un colegio, hormigonada de una vez con encofrado de tablas. Tres ventanas redondas iluminan la sala desde lo alto; una banda de cobre marca la entrada.</p>',
                        'credits' => 'Lucía Arroyo, Henrik Lind, Marta Climent',
                    ],
                ],
            ],
            [
                'title' => 'House on the Terraces', 'slug' => 'house-on-the-terraces', 'days_ago' => 70,
                'data' => [
                    'number' => 12, 'place' => 'Altea', 'year' => 2024, 'use' => 'housing', 'area' => 310, 'status' => 'built',
                    'photos' => ['@photo:terraces'],
                    'body' => '<p>A house for a family of five on old farming terraces above the sea. Each room sits on its own terrace, so the house steps down the hill instead of levelling it; the concrete keeps the marks of the boards it was cast against.</p>',
                    'credits' => 'Henrik Lind, Pau Ferrer',
                ],
                'es' => [
                    'title' => 'Casa en las terrazas', 'slug' => 'casa-en-las-terrazas',
                    'data' => [
                        'body' => '<p>Una casa para una familia de cinco sobre antiguos bancales de cultivo frente al mar. Cada estancia ocupa su propio bancal, de modo que la casa baja por la ladera en lugar de allanarla; el hormigón conserva las marcas de las tablas del encofrado.</p>',
                        'credits' => 'Henrik Lind, Pau Ferrer',
                    ],
                ],
            ],
            [
                'title' => 'Twelve Flats in Ruzafa', 'slug' => 'twelve-flats-ruzafa', 'days_ago' => 20,
                'data' => [
                    'number' => 13, 'place' => 'Valencia', 'year' => 2025, 'use' => 'housing', 'area' => 1240, 'status' => 'construction',
                    'photos' => ['@photo:flats'],
                    'body' => '<p>Twelve flats for a housing cooperative, on a narrow plot between two older buildings. The brick front picks up the street\'s arches; behind it, every flat runs through from the street to a shared garden.</p>',
                    'credits' => 'Lucía Arroyo, Marta Climent, Pau Ferrer',
                ],
                'es' => [
                    'title' => 'Doce viviendas en Ruzafa', 'slug' => 'doce-viviendas-en-ruzafa',
                    'data' => [
                        'body' => '<p>Doce viviendas para una cooperativa, en un solar estrecho entre dos edificios antiguos. La fachada de ladrillo recoge los arcos de la calle; detrás, cada vivienda va de la calle a un jardín compartido.</p>',
                        'credits' => 'Lucía Arroyo, Marta Climent, Pau Ferrer',
                    ],
                ],
            ],
        ],

        // "starts_in_days" and "starts_at" (local time, Europe/Madrid) set the talk's start
        'talks' => [
            [
                'title' => 'Drawing by Hand, Still', 'slug' => 'drawing-by-hand', 'days_ago' => 60, 'starts_in_days' => -40, 'starts_at' => '19:00',
                'data' => ['place' => 'In the studio', 'body' => '<p>Lucía Arroyo on why every project here starts with a pencil, and what a drawing made on site catches that a survey does not.</p>'],
                'es' => ['title' => 'Dibujar a mano, todavía', 'slug' => 'dibujar-a-mano', 'data' => ['place' => 'En el taller', 'body' => '<p>Lucía Arroyo explica por qué cada proyecto empieza aquí con un lápiz, y qué recoge un dibujo hecho en el lugar que no recoge un levantamiento.</p>']],
            ],
            [
                'title' => 'Building in Brick Again', 'slug' => 'building-in-brick-again', 'days_ago' => 10, 'starts_in_days' => 12, 'starts_at' => '19:00',
                'data' => ['place' => 'In the studio', 'body' => '<p>Henrik Lind and Marta Climent on the twelve flats in Ruzafa: laying a brick front by hand in a city that stopped doing it, and what it costs.</p>'],
                'es' => ['title' => 'Volver al ladrillo', 'slug' => 'volver-al-ladrillo', 'data' => ['place' => 'En el taller', 'body' => '<p>Henrik Lind y Marta Climent sobre las doce viviendas de Ruzafa: levantar a mano una fachada de ladrillo en una ciudad que dejó de hacerlo, y lo que cuesta.</p>']],
            ],
            [
                'title' => 'Open Studio: Models', 'slug' => 'open-studio-models', 'days_ago' => 5, 'starts_in_days' => 33, 'starts_at' => '11:00',
                'data' => ['place' => 'In the studio', 'body' => '<p>A Saturday morning with the models of every project on the tables. Pau Ferrer shows how they are made; children are welcome.</p>'],
                'es' => ['title' => 'Taller abierto: maquetas', 'slug' => 'taller-abierto-maquetas', 'data' => ['place' => 'En el taller', 'body' => '<p>Una mañana de sábado con las maquetas de todos los proyectos sobre las mesas. Pau Ferrer enseña cómo se hacen; los niños son bienvenidos.</p>']],
            ],
        ],

        'team' => [
            [
                'title' => 'Lucía Arroyo', 'slug' => 'lucia-arroyo', 'days_ago' => 500,
                'data' => ['role' => 'Architect, partner', 'body' => '<p>Lucía draws every project first. Before starting the studio she spent ten years on schools and libraries for others.</p>'],
                'es' => ['title' => 'Lucía Arroyo', 'slug' => 'lucia-arroyo', 'data' => ['role' => 'Arquitecta, socia', 'body' => '<p>Lucía dibuja primero cada proyecto. Antes de fundar el estudio pasó diez años haciendo escuelas y bibliotecas para otros.</p>']],
            ],
            [
                'title' => 'Henrik Lind', 'slug' => 'henrik-lind', 'days_ago' => 500,
                'data' => ['role' => 'Architect, partner', 'body' => '<p>Henrik runs the sites. He came to Valencia from Aarhus to study and stayed for the light and the brick.</p>'],
                'es' => ['title' => 'Henrik Lind', 'slug' => 'henrik-lind', 'data' => ['role' => 'Arquitecto, socio', 'body' => '<p>Henrik lleva las obras. Llegó a Valencia desde Aarhus para estudiar y se quedó por la luz y el ladrillo.</p>']],
            ],
            [
                'title' => 'Marta Climent', 'slug' => 'marta-climent', 'days_ago' => 300,
                'data' => ['role' => 'Project architect', 'body' => '<p>Marta leads the housing work, from the first meeting with a cooperative to the keys.</p>'],
                'es' => ['title' => 'Marta Climent', 'slug' => 'marta-climent', 'data' => ['role' => 'Arquitecta de proyecto', 'body' => '<p>Marta dirige los proyectos de vivienda, desde la primera reunión con una cooperativa hasta la entrega de llaves.</p>']],
            ],
            [
                'title' => 'Pau Ferrer', 'slug' => 'pau-ferrer', 'days_ago' => 200,
                'data' => ['role' => 'Model maker', 'body' => '<p>Pau builds the models, in card for the first week of a project and in timber once it is settled.</p>'],
                'es' => ['title' => 'Pau Ferrer', 'slug' => 'pau-ferrer', 'data' => ['role' => 'Maquetista', 'body' => '<p>Pau construye las maquetas: de cartón la primera semana de un proyecto y de madera cuando ya está decidido.</p>']],
            ],
        ],

        // the journal: core's posts, with their category by English name
        'post' => [
            [
                'title' => 'Learning from Vanvitelli\'s Sketches', 'slug' => 'vanvitelli-sketches', 'days_ago' => 90, 'category' => 'Drawing',
                'excerpt' => 'A sheet of quick studies for a doorway, and what it says about drawing to decide.',
                'data' => ['image' => '@photo:vanvitelli', 'body' => [
                    $text('<p>The Metropolitan Museum of Art keeps a sheet of studies by Luigi Vanvitelli: a wall with a doorway, and two smaller sketches beside it trying other windows. Nothing on it is finished. Each line is there to decide something.</p>'),
                    $text('<p>That is the kind of drawing we try to make on a first visit: fast, a little wrong, and clear about the one question it is asking.</p>'),
                    $quote('A drawing that tries to answer everything answers nothing.'),
                    $text('<p>We pinned a copy above the big table. When a sketch of ours starts to fill up with detail, someone points at it, and we start again on a smaller sheet.</p>'),
                ]],
                'es' => ['title' => 'Aprender de los bocetos de Vanvitelli', 'slug' => 'bocetos-de-vanvitelli', 'excerpt' => 'Una hoja de estudios rápidos para una puerta, y lo que dice sobre dibujar para decidir.', 'data' => ['body' => [
                    $text('<p>El Metropolitan Museum of Art conserva una hoja de estudios de Luigi Vanvitelli: un muro con una puerta y, al lado, dos bocetos más pequeños que prueban otras ventanas. Nada está terminado. Cada línea está ahí para decidir algo.</p>'),
                    $text('<p>Ese es el tipo de dibujo que intentamos hacer en una primera visita: rápido, algo impreciso y claro sobre la única pregunta que se hace.</p>'),
                    $quote('Un dibujo que intenta responderlo todo no responde nada.'),
                    $text('<p>Colgamos una copia encima de la mesa grande. Cuando un boceto nuestro empieza a llenarse de detalles, alguien la señala y empezamos de nuevo en una hoja más pequeña.</p>'),
                ]]],
            ],
            [
                'title' => 'A Model of Chochikukyo', 'slug' => 'chochikukyo-model', 'days_ago' => 45, 'category' => 'Visits',
                'excerpt' => 'Kōji Fujii\'s house of 1928, cut open in an exhibition model.',
                'data' => ['image' => '@photo:chochikukyo', 'body' => [
                    $text('<p>Chochikukyo, the house the architect Kōji Fujii built for himself near Kyoto in 1928, was shown in an exhibition as a cut-away model. With the roof off, you can follow how the rooms are arranged for air and shade.</p>'),
                    $text('<p>What held us was how plain the model is. Card for the walls, thin strips for the screens, nothing painted. It explains the house the way a section drawing does, but you can walk around it.</p>'),
                    $quote('With the roof off, a house tells you how it breathes.'),
                    $text('<p>We came back and made a cut-away of our own for the house on the terraces, in grey card, at one to fifty. It now sits by the door, and clients lift the roof before they sit down.</p>'),
                ]],
                'es' => ['title' => 'Una maqueta de Chochikukyo', 'slug' => 'maqueta-de-chochikukyo', 'excerpt' => 'La casa de Kōji Fujii de 1928, abierta en una maqueta de exposición.', 'data' => ['body' => [
                    $text('<p>Chochikukyo, la casa que el arquitecto Kōji Fujii construyó para sí mismo cerca de Kioto en 1928, se mostraba en una exposición como una maqueta seccionada. Sin la cubierta se puede seguir cómo se ordenan las estancias para el aire y la sombra.</p>'),
                    $text('<p>Lo que nos retuvo fue lo sencilla que es la maqueta. Cartón para los muros, tiras finas para los paneles, nada pintado. Explica la casa como lo hace una sección, pero se puede rodear.</p>'),
                    $quote('Sin la cubierta, una casa te cuenta cómo respira.'),
                    $text('<p>Volvimos e hicimos una maqueta seccionada propia para la casa en las terrazas, en cartón gris, a escala uno a cincuenta. Ahora está junto a la puerta, y los clientes levantan la cubierta antes de sentarse.</p>'),
                ]]],
            ],
            [
                'title' => 'Courtyards in Urbino', 'slug' => 'courtyards-in-urbino', 'days_ago' => 15, 'category' => 'Visits',
                'excerpt' => 'A week of drawing the courtyards behind the streets.',
                'data' => ['image' => '@photo:urbino', 'body' => [
                    $text('<p>Behind the streets of Urbino are courtyards: arches on one side, a garden on another, roofs stepping down the hill. We spent a week drawing them.</p>'),
                    $text('<p>Each morning we picked one and stayed until the light left it. By the third day we had stopped measuring and started counting steps instead: from the gate to the well, from the arches to the shade.</p>'),
                    $quote('Each courtyard is shared by houses that never quite line up, and it matters very little.'),
                    $text('<p>Back in Valencia, the twelve flats in Ruzafa got a courtyard that does the same: a little out of square, planted, and walked through on the way to every door.</p>'),
                ]],
                'es' => ['title' => 'Patios en Urbino', 'slug' => 'patios-en-urbino', 'excerpt' => 'Una semana dibujando los patios detrás de las calles.', 'data' => ['body' => [
                    $text('<p>Detrás de las calles de Urbino hay patios: arcos a un lado, un jardín al otro, tejados que bajan por la ladera. Pasamos una semana dibujándolos.</p>'),
                    $text('<p>Cada mañana elegíamos uno y nos quedábamos hasta que la luz se iba. Al tercer día ya no medíamos: contábamos pasos, de la puerta al pozo, de los arcos a la sombra.</p>'),
                    $quote('Cada patio lo comparten casas que nunca acaban de alinearse, y eso importa muy poco.'),
                    $text('<p>De vuelta en Valencia, las doce viviendas de Ruzafa tienen un patio que hace lo mismo: algo fuera de escuadra, plantado y atravesado de camino a cada puerta.</p>'),
                ]]],
            ],
        ],
    ],

    /* Archive items name a type; entry items a page key. Labels are for archives. */
    'menus' => [
        [
            'name' => 'Rooms', 'location' => 'primary', 'locale' => '',
            'items' => [['archive', 'projects', 'Projects'], ['page', 'studio'], ['archive', 'talks', 'Talks'], ['archive', 'post', 'Journal']],
        ],
        [
            'name' => 'Salas', 'location' => 'primary', 'locale' => 'es',
            'items' => [['archive', 'projects', 'Proyectos'], ['page', 'studio'], ['archive', 'talks', 'Charlas'], ['archive', 'post', 'Diario']],
        ],
        [
            'name' => 'Footer', 'location' => 'footer', 'locale' => '',
            'items' => [['page', 'contact'], ['archive', 'team', 'People']],
        ],
        [
            'name' => 'Pie', 'location' => 'footer', 'locale' => 'es',
            'items' => [['page', 'contact'], ['archive', 'team', 'Equipo']],
        ],
    ],
];
