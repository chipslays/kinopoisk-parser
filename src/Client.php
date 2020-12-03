<?php

namespace Kinopoisk;

use GuzzleHttp\Client as Http;
use DiDom\Document;
use Kinopoisk\Util\Collection;

class Client
{
    /**
     * @var Http
     */
    private $http;

    private $attempts = 0;

    /**
     * @var x-token GraphQL
     */
    private $xToken;

    /**
     * Массив с сообщением об ошибке
     *
     * @var boolean|array
     */
    private $errors = false;

    /**
     * Kinopoisk GraphQL Api url
     */
    private const GRAPHQL_API = 'https://ma.kinopoisk.ru/api-frontend//graphql';

    private const KINOPOISK_URL = 'https://www.kinopoisk.ru/';

    private const FILM_HEADERS = [
        'Referer' => 'https://www.kinopoisk.ru/',
        'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.9',
        'Accept-Encoding' => 'gzip, deflate, br',
        'Accept-Language' => 'ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
        'Cache-Control' => 'no-cache',
        'Pragma' => 'no-cache',
        'Sec-Fetch-Dest' => 'document',
        'Sec-Fetch-Mode' => 'navigate',
        'Sec-Fetch-Site' => 'same-origin',
        'Sec-Fetch-User' => '?1',
        'Upgrade-Insecure-Requests' => '1',
    ];
    
    /**
     * BLOOPER - киноляпы
     * FACT - факты
     */
    private const ALLOW_TRIVIAL_TYPE = ['BLOOPER', 'FACT'];

    private $userAgent;

    public function __construct()
    {
        $this->http = new Http([
            'base_uri' => self::KINOPOISK_URL,
            'timeout'  => 60.0,
            'proxy' => 'socks5://127.0.0.1:9050',
            'headers' => [
                'Referer' => self::KINOPOISK_URL,
            ],
        ]);

        $this->setUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/87.0.4280.66 Safari/537.36');
    }

    public function getFilmById($filmId, $attempts = 1)
    {
        $this->attempts++;

        $response = $this->http->get("/film/{$filmId}", [
            'headers' => array_merge(self::FILM_HEADERS, ['User-Agent' => $this->userAgent]),
            'http_errors' => false,
        ]);

        if ($this->hasErrors($response)) {
            return $this->getErrors();
        }

        $body = (string) $response->getBody();
        
        if (stripos($body, '__NEXT_DATA__') == false) {
            $this->torServiceRestart();
            return $this->attempts < $attempts ? $this->getFilmById($filmId) : false;
        }

        $document = new Document($body);
        $text = $document->first('#__NEXT_DATA__')->text();
        $text = mb_convert_encoding($text, 'UTF-8', "HTML-ENTITIES");
        $data = json_decode($text, true);

        $text = $document->first('script[type="application/ld+json"]')->text();
        $text = mb_convert_encoding($text, 'UTF-8', "HTML-ENTITIES");
        $schema = json_decode($text, true);

        file_put_contents(__DIR__.'/data.json', json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        file_put_contents(__DIR__.'/schema.json', json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        // debug start
        // $text = file_get_contents(__DIR__.'/data.json');
        // $data = json_decode($text, true);
        // $text = file_get_contents(__DIR__.'/schema.json');
        // $schema = json_decode($text, true);
        // debug end
        
        $data = new Collection($data);
        $schema = new Collection($schema);

        $this->xToken = $data->get('props.apolloState.context.credentials.token')->first();
        
        $duration = $data->get('props.initialState.entities.movieOnlineViewOption.*.contentMetadata.duration')->first();

        $persons = $data->get('props.apolloState.data', [])->map(function ($item, $key) {
            if (stripos($key, 'Person:') !== false) {
                return [
                    'id' => $item['id'],
                    'type' => $item['__typename'],
                    'name' => [
                        'ru' => $item['name'],
                        'original' => $item['originalName'],
                    ],
                ];
            }
            return null;
        })->filter()->values()->toArray();
        $persons = new Collection($persons);

        $result = [
            'filmId' => $data->get('props.initialState.entities.movieOnlineViewOption.*.filmId', false)->first(),
            'kpId' => $data->get('props.initialState.entities.movieOnlineViewOption.*.contentMetadata.kpId', false)->first(),
            'path' => $data->get('props.initialProps.pageProps.originalUrl', false)->first(),
            'url' => $schema->get('url', false)->first(),
            'type' => $data->get('props.initialState.entities.movieOnlineViewOption.*.contentMetadata.contentType', false)->first(),
            'age' => $data->get('props.initialState.entities.movieOnlineViewOption.*.contentMetadata.restrictionAge', false)->first(),
            'year' => $data->get('props.apolloState.data.*.productionYear', false)->first(),
            'isFamilyFriendly' => $schema->get('isFamilyFriendly', null)->first(),
            'duration' => [
                's' => $duration,
                'h:m' => date('H:i', $duration),
                'h:m:s' => date('H:i:s', $duration),
                'print' => date('H ч. i мин.', $duration),
            ],
            'title' => [
                'ru' => $data->get('props.initialState.entities.movieOnlineViewOption.*.contentMetadata.title', false)->first(),
                'original' => $data->get('props.initialState.entities.movieOnlineViewOption.*.contentMetadata.originalTitle', false)->first() ?? $schema->get('name', false)->first(),
            ],
            'slogan' => $data->get('props.apolloState.data.*.tagline', false)->first(),
            'description' => [
                'full' => $data->get('props.initialState.entities.movieOnlineViewOption.*.contentMetadata.description', false)->first(),
                'short' => $data->get('props.initialState.entities.movieOnlineViewOption.*.contentMetadata.shortDescription', false)->first(),
            ],
            'image' => [
                'poster' => $data->get('props.initialState.entities.movieOnlineViewOption.*.contentMetadata.posterUrl', false)->first(),
                'posterPrimaryColor' => $data->get('props.initialState.entities.movieOnlineViewOption.*.contentMetadata.posterMedianColor', false)->first(),
                'cover' => $data->get('props.initialState.entities.movieOnlineViewOption.*.contentMetadata.coverUrl', false)->first(),
                'coverColorTheme' => $data->get('props.initialState.entities.movieOnlineViewOption.*.contentMetadata.coverLogoRecommendedTheme', false)->first(),
            ],
            'rating' => [
                'kinopoisk' => $data->get("props.apolloState.data", false)->map(function ($item, $key) use ($filmId) {
                    if ($key == "\$Film:{$filmId}.rating.kinopoisk") {
                        return [
                            'rate' => $item['value'],
                            'votes' => $item['count'],
                        ];
                    }
                    return null;
                })->filter()->values()->first(),
                'imdb' =>  $data->get("props.apolloState.data", false)->map(function ($item, $key) use ($filmId) {
                    if ($key == "\$Film:{$filmId}.rating.imdb") {
                        return [
                            'rate' => $item['value'],
                            'votes' => $item['count'],
                        ];
                    }
                    return null;
                })->filter()->values()->first(),
                'critics' => [
                    'russian' => $data->get("props.apolloState.data", false)->map(function ($item, $key) use ($filmId) {
                        if ($key == "\$Film:{$filmId}.rating.russianCritics") {
                            return [
                                'rate' => $item['value'],
                                'votes' => $item['count'],
                            ];
                        }
                        return null;
                    })->filter()->values()->first(),
                    'worldwide' => $data->get("props.apolloState.data", false)->map(function ($item, $key) use ($filmId) {
                        if ($key == "\$Film:{$filmId}.rating.worldwideCritics") {
                            return [
                                'rate' => $item['value'],
                                'votes' => $item['count'],
                                'positiveVotes' => $item['positiveCount'],
                                'negativeVotes' => $item['negativeCount'],
                                'percent' => $item['percent'],
                            ];
                        }
                        return null;
                    })->filter()->values()->first(),
                ],
            ],
            'genre' => $data->get("props.apolloState.data", false)->map(function ($item, $key) use ($filmId) {
                if (stripos($key, "Film:{$filmId}.genres") !== false) {
                    return [
                        'id' => $item['id'],
                        'name' => mb_ucfirst($item['name']),
                        'slug' => $item['slug'],
                    ];
                }
                return null;
            })->filter()->values()->toArray(),
            'country' => $data->get('props.initialState.entities.movieOnlineViewOption.*.contentMetadata.countries', [])->first() ?? $schema->get('countryOfOrigin', false)->first(),
            'trailer' => $data->get('props.initialState.entities.movieOnlineViewOption.*.trailers.*', false)->map(function ($item) {
                return [
                    'main' => (bool) $item['main'],
                    'trailer' => $item['streamUrl'],
                ];
            })->sortByDesc('main')->values()->filter()->toArray(),
            'director' => $schema->get('director', false)->map(function ($item) use ($persons) {
                return [
                    'id' => $item['url'] ? last(explode('/', $item['url'])) : false,
                    'name' => [
                        'ru' => $item['name'],
                        'original' => $persons->where('name.ru', $item['name'])->get('*.name.original')->first(),
                    ],
                ];
            })->filter()->values()->toArray(),
            'producer' => $schema->get('producer', false)->map(function ($item) use ($persons) {
                return [
                    'id' => $item['url'] ? last(explode('/', $item['url'])) : false,
                    'name' => [
                        'ru' => $item['name'],
                        'original' => $persons->where('name.ru', $item['name'])->get('*.name.original')->first(),
                    ],
                ];
            })->filter()->values()->toArray(),
            'actor' => $schema->get('actor', false)->map(function ($item) use ($persons) {
                return [
                    'id' => $item['url'] ? last(explode('/', $item['url'])) : false,
                    'name' => [
                        'ru' => $item['name'],
                        'original' => $persons->where('name.ru', $item['name'])->get('*.name.original')->first(),
                    ],
                ];
            })->filter()->values()->toArray(),
            'money' => [
                'budget' => [
                    'amount' => @$data['props']['apolloState']['data']["\$Film:{$filmId}.boxOffice.budget"]['amount'],
                    'currency' => @$data['props']['apolloState']['data']["\$Film:{$filmId}.boxOffice.budget.currency"]['symbol'],
                    'print' => @$data['props']['apolloState']['data']["\$Film:{$filmId}.boxOffice.budget.currency"]['symbol'].
                        number_format(@$data['props']['apolloState']['data']["\$Film:{$filmId}.boxOffice.budget"]['amount'], 0, ',', ' '),
                ],
                'fees' => [
                    'russia' => [
                        'amount' => @$data['props']['apolloState']['data']["\$Film:{$filmId}.boxOffice.rusBox"]['amount'],
                        'currency' => @$data['props']['apolloState']['data']["\$Film:{$filmId}.boxOffice.rusBox.currency"]['symbol'],
                        'print' => @$data['props']['apolloState']['data']["\$Film:{$filmId}.boxOffice.rusBox.currency"]['symbol'].
                            number_format(@$data['props']['apolloState']['data']["\$Film:{$filmId}.boxOffice.rusBox"]['amount'], 0, ',', ' '),
                    ],
                    'usa' => [
                        'amount' => @$data['props']['apolloState']['data']["\$Film:{$filmId}.boxOffice.usaBox"]['amount'],
                        'currency' => @$data['props']['apolloState']['data']["\$Film:{$filmId}.boxOffice.usaBox.currency"]['symbol'],
                        'print' => @$data['props']['apolloState']['data']["\$Film:{$filmId}.boxOffice.usaBox.currency"]['symbol'].
                            number_format(@$data['props']['apolloState']['data']["\$Film:{$filmId}.boxOffice.usaBox"]['amount'], 0, ',', ' '),
                    ],
                    'world' => [
                        'amount' => @$data['props']['apolloState']['data']["\$Film:{$filmId}.boxOffice.worldBox"]['amount'],
                        'currency' => @$data['props']['apolloState']['data']["\$Film:{$filmId}.boxOffice.worldBox.currency"]['symbol'],
                        'print' => @$data['props']['apolloState']['data']["\$Film:{$filmId}.boxOffice.worldBox.currency"]['symbol'].
                            number_format(@$data['props']['apolloState']['data']["\$Film:{$filmId}.boxOffice.worldBox"]['amount'], 0, ',', ' '),
                    ],
                ],
            ],
            'premiere' => [
                'russia' => $data->get("props.apolloState.data", false)->map(function ($item, $key) use ($filmId) {
                    if (stripos($key, "Film:{$filmId}.distribution.releases({\"countryId\":2,\"limit\":1,\"rerelease\":false,\"types\":[\"CINEMA\"]}).items.0.date") !== false && isset($item['date'])) {
                        return [
                            'timestamp' => strtotime($item['date']),
                            'date' => date('d.m.Y', strtotime($item['date'])),
                        ];
                    }
                    return null;
                })->filter()->values()->first(),
                'world' => $data->get("props.apolloState.data", false)->map(function ($item, $key) use ($filmId) {
                    if (stripos($key, "Film:{$filmId}.worldPremiere") !== false && isset($item['date'])) {
                        return [
                            'timestamp' => strtotime($item['date']),
                            'date' => date('d.m.Y', strtotime($item['date'])),
                        ];
                    }
                    return null;
                })->filter()->values()->first(),
                'digital' => $data->get("props.apolloState.data", false)->map(function ($item, $key) use ($filmId) {
                    if (stripos($key, "Film:{$filmId}.releases") !== false && isset($item['date']) && $item['type'] == 'DIGITAL') {
                        return [
                            'timestamp' => strtotime($item['date']),
                            'date' => date('d.m.Y', strtotime($item['date'])),
                        ];
                    }
                    return null;
                })->filter()->values()->first(),
            ],
            'fact' => $this->getAllFactsByFilmId($filmId),
            'blooper' => $this->getAllBloopersByFilmId($filmId),
        ];

        return $result;
    }

    private function hasErrors($response)
    {
        $code = $response->getStatusCode();
        $hasErorrs = $code !== 200;
        $this->errors = $hasErorrs ? $this->showError($code, $response) : false;
        return $hasErorrs;
    }

    private function getErrors()
    {
        return $this->errors;
    }

    public function getTrivialByFilmId($filmId, $trivial = false, $limit = 10, $offset = 0)
    {
        $trivial = mb_strtoupper($trivial);
        
        if (!in_array($trivial, self::ALLOW_TRIVIAL_TYPE) || !$trivial) {
            return false;
        }

        // Если больше 10 элементов, то будет ошибка.
        $limitMax = 10;
       
        $limit = $limit > $limitMax ? $limitMax : $limit;
       
        $response = $this->http->post(self::GRAPHQL_API, [
            'headers' => [
                'Content-Type' => 'application/json',
                'User-Agent' => $this->userAgent,
                'x-token' => $this->xToken,
            ],
            'json' => [
                'operationName' => 'FilmTrivias',
                'variables' => [
                    'triviasLimit' => (int) $limit,
                    'triviasOffset' => (int) $offset,
                    'filmId' => (int) $filmId,
                    'triviasType' => $trivial,
                ],
                'query' => 'query FilmTrivias($filmId: Long!, $triviasType: TriviaType!, $triviasLimit: Int = 10, $triviasOffset: Int = 0) {film(id: $filmId) {  id  trivias(type: $triviasType, limit: $triviasLimit, offset: $triviasOffset) {    items {      id      isSpoiler       text       type       __typename     }     total     limit     offset     __typename   }    __typename  }}',
            ],
            'http_errors' => false,
        ]);

        if ($this->hasErrors($response)) {
            return $this->getErrors();
        }
       
        $body = (string) $response->getBody();
        $data = new Collection(json_decode($body, true));

        $total = $data->get('data.film.trivias.total', 0)->first();
        $nextLimit = (int) max($total - ($limit + $offset), 0);
        $nextOffset = max($offset + $limit, 0);

        return [    
            'pagination' => [
                'hasNextPage' => $nextOffset < $total && $total !== 0 ? true : false,
                'total' => $total,
                'next' => [
                    'limit' => $nextLimit > $limitMax ? $limitMax : $nextLimit,
                    'offset' => $nextOffset >= $total ? 0 : $nextOffset,
                ],
                'current' => [
                    'limit' => $limit,
                    'offset' => $offset,
                ],
            ],
            'items' => $data->get('data.film.trivias.items', [])->map(function ($item) {
                return [
                    'spoiler' => $item['isSpoiler'],
                    'text' => [
                        'clean' => strip_tags($item['text']),
                        'raw' => $item['text'],
                    ]
                ];
            })->toArray(),
        ];
    }

    public function getAllTrivialByFilmId($filmId, $trivial = false) 
    {
        if (!in_array($trivial, self::ALLOW_TRIVIAL_TYPE) || !$trivial) {
            return false;
        }

        $trivials = [];
        $hasNextPage = true;
        $limit = 10;
        $offset = 0;
        while ($hasNextPage) {
            $res = $this->getTrivialByFilmId($filmId, $trivial, $limit, $offset);

            if (isset($res['error'])) {
                break;
            }

            $trivials = array_merge($trivials, $res['items']);

            $hasNextPage = $res['pagination']['hasNextPage'];
            $limit = $res['pagination']['next']['limit'];
            $offset = $res['pagination']['next']['offset'];
        }
        
        if (count($trivials) == 0) {
            return false;
        }

        $trivials = (new Collection($trivials))->groupBy('spoiler')->toArray();
        $trivials['safe'] = $trivials[0];
        $trivials['spoiler'] = $trivials[1];

        unset($trivials[0]);
        unset($trivials[1]);

        return $trivials;
    }

    function getFactsByFilmId($filmId, $limit = 10, $offset = 0) 
    {
        return $this->getTrivialByFilmId($filmId, 'FACT', $limit, $offset);
    }

    public function getAllFactsByFilmId($filmId)
    {
        return $this->getAllTrivialByFilmId($filmId, 'FACT');
    }

    function getBloopersByFilmId($filmId, $limit = 10, $offset = 0) 
    {
        return $this->getTrivialByFilmId($filmId, 'BLOOPER', $limit, $offset);
    }
    
    public function getAllBloopersByFilmId($filmId)
    {
        return $this->getAllTrivialByFilmId($filmId, 'BLOOPER');
    }

    public function torServiceRestart()
    {
        switch (PHP_OS_FAMILY) {
            case 'Linux':
                exec('service tor restart');
                break;
            case 'Windows':
                exec('net stop tor');
                exec('net start tor');
                break;
        }
    }

    private function showError($code, $response) 
    {
        return [
            'error' => true,
            'code' => $code,
            'message' => $response->getReasonPhrase(),
            'body' => json_decode((string) $response->getBody() ?? '{}', true),
        ];
    }

    public function setUserAgent($userAgent = null)
    {
        $this->userAgent = $userAgent;
    }
}
