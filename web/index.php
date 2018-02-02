<?php

require __DIR__ . '/../vendor/autoload.php';

use Symfony\Component\Cache\Simple\FilesystemCache;
use League\Fractal\Manager;
use League\Fractal\Resource\Collection;
use Slim\Http\Request;
use Slim\Exception\NotFoundException;

(new Dotenv\Dotenv(__DIR__ . '/..'))->load();

Crew\Unsplash\HttpClient::init([
    'applicationId' => getenv('UNSPLASH_APP_ID'),
    'secret' => getenv('UNSPLASH_APP_SECRET'),
    'callbackUrl' => getenv('UNSPLASH_CALLBACK_URL'),
    'utmSource' => getenv('UNSPLASH_UTM_SOURCE')
]);

$collections = [
    [
        'name' => 'portraits',
        'id' => 302501
    ],
    [
        'name' => 'faces',
        'id' => 1236
    ],
    [
        'name' => 'workspaces',
        'id' => 430077
    ],
    [
        'name' => 'offices',
        'id' => 618393
    ],
    [
        'name' => 'nature',
        'id' => 158642
    ]
];

$cache = new FilesystemCache();
$fractal = new Manager();

$app = new Slim\App();

$app->get('/', function (Request $request, $response, $args) use ($collections, $cache, $fractal) {
    $router = $this->get('router');

    $uri = $request->getUri();
    $scheme = $uri->getScheme();
    $authority = $uri->getAuthority();
    $basePath = $uri->getBasePath();
    $path = $uri->getPath();

    $path = $basePath . '/' . ltrim($path, '/');

    $baseUrl = ($scheme ? $scheme . ':' : '')
        . ($authority ? '//' . $authority : '')
        . $path;

    $resource = new Collection($collections, function ($endpoint) use ($cache, $router, $baseUrl) {
        $cacheName = "app.collection-{$endpoint['name']}";

        if (!$cache->has($cacheName)) {
            $collection = Crew\Unsplash\Collection::find($endpoint['id']);

            $cache->set($cacheName, $collection);
        }

        $collection = $cache->get($cacheName);

        $path = $router->pathFor('collection', [
            'name' => $endpoint['name']
        ]);

        return [
            'name' => $endpoint['name'],
            'uri' => $baseUrl . ltrim($path, '/')
        ];
    });

    return $response->getBody()->write($fractal->createData($resource)->toJson());
});

$app->get('/{name}', function ($request, $response, $args) use ($cache, $fractal, $collections) {
    $collectionCacheName = "app.collection-{$args['name']}";
    $photosCacheName = "app.collection-{$args['name']}.photos";

    $collection = array_filter($collections, function ($item) use ($args) {
        return $item['name'] === $args['name'];
    });

    if (empty($collection)) {
        throw new NotFoundException($request, $response);
    }

    $collection = array_shift($collection);

    if (!$cache->has($photosCacheName)) {
        if ($cache->has($collectionCacheName)) {
            $collection = Crew\Unsplash\Collection::find($collection['id']);
            $cache->set($collectionCacheName, $collection);
        }

        $collection = $cache->get($collectionCacheName);

        $photos = $collection->photos(0, 30);
        $cache->set($photosCacheName, $photos);
    }

    $resource = new Collection($cache->get($photosCacheName), function ($photo) {
        return [
            'id' => $photo->id,
            'created_at' => $photo->created_at,
            'width' => (int) $photo->width,
            'height' => (int) $photo->height,
            'color' => $photo->color,
            'description' => $photo->description,
            'urls' => (array) $photo->urls,
            'links' => (array) $photo->links
        ];
    });

    return $response->getBody()->write($fractal->createData($resource)->toJson());
})->setName('collection');

$app->run();
