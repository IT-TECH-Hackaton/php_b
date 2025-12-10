<?php

namespace App\Handlers;

use App\Config\Config;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;

class GeocoderHandler
{
    public function geocodeAddress(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $data = json_decode($request->getBody()->getContents(), true);
        
        if (!isset($data['address'])) {
            $response->getBody()->write(json_encode(['error' => 'Неверные данные']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        
        if (empty(Config::$yandexGeocoderApiKey)) {
            $response->getBody()->write(json_encode(['error' => 'Яндекс.Геокодер не настроен']));
            return $response->withStatus(503)->withHeader('Content-Type', 'application/json');
        }
        
        $url = sprintf(
            'https://geocode-maps.yandex.ru/1.x/?apikey=%s&geocode=%s&format=json',
            Config::$yandexGeocoderApiKey,
            urlencode($data['address'])
        );
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            $response->getBody()->write(json_encode(['error' => 'Ошибка при обращении к геокодеру']));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
        
        $geocodeData = json_decode($result, true);
        
        if (!isset($geocodeData['response']['GeoObjectCollection']['featureMember'][0])) {
            $response->getBody()->write(json_encode(['error' => 'Адрес не найден']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }
        
        $geoObject = $geocodeData['response']['GeoObjectCollection']['featureMember'][0]['GeoObject'];
        $pos = $geoObject['Point']['pos'];
        list($longitude, $latitude) = explode(' ', $pos);
        
        $yandexMapLink = sprintf('https://yandex.ru/maps/?pt=%s,%s&z=16', $longitude, $latitude);
        
        $response->getBody()->write(json_encode([
            'latitude' => (float)$latitude,
            'longitude' => (float)$longitude,
            'address' => $geoObject['metaDataProperty']['GeocoderMetaData']['text'],
            'yandexMapLink' => $yandexMapLink
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function reverseGeocode(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $data = json_decode($request->getBody()->getContents(), true);
        
        if (!isset($data['latitude']) || !isset($data['longitude'])) {
            $response->getBody()->write(json_encode(['error' => 'Неверные данные']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        
        $latitude = (float)$data['latitude'];
        $longitude = (float)$data['longitude'];
        
        if ($latitude < -90 || $latitude > 90) {
            $response->getBody()->write(json_encode(['error' => 'Широта должна быть от -90 до 90']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        
        if ($longitude < -180 || $longitude > 180) {
            $response->getBody()->write(json_encode(['error' => 'Долгота должна быть от -180 до 180']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        
        if (empty(Config::$yandexGeocoderApiKey)) {
            $response->getBody()->write(json_encode(['error' => 'Яндекс.Геокодер не настроен']));
            return $response->withStatus(503)->withHeader('Content-Type', 'application/json');
        }
        
        $url = sprintf(
            'https://geocode-maps.yandex.ru/1.x/?apikey=%s&geocode=%s,%s&format=json',
            Config::$yandexGeocoderApiKey,
            $longitude,
            $latitude
        );
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            $response->getBody()->write(json_encode(['error' => 'Ошибка при обращении к геокодеру']));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
        
        $geocodeData = json_decode($result, true);
        
        if (!isset($geocodeData['response']['GeoObjectCollection']['featureMember'][0])) {
            $response->getBody()->write(json_encode(['error' => 'Адрес не найден для данных координат']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }
        
        $geoObject = $geocodeData['response']['GeoObjectCollection']['featureMember'][0]['GeoObject'];
        $yandexMapLink = sprintf('https://yandex.ru/maps/?pt=%s,%s&z=16', $longitude, $latitude);
        
        $response->getBody()->write(json_encode([
            'latitude' => $latitude,
            'longitude' => $longitude,
            'address' => $geoObject['metaDataProperty']['GeocoderMetaData']['text'],
            'yandexMapLink' => $yandexMapLink
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function generateMapLink(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $data = json_decode($request->getBody()->getContents(), true);
        
        $mapLink = null;
        
        if (isset($data['latitude']) && isset($data['longitude'])) {
            $mapLink = sprintf('https://yandex.ru/maps/?pt=%s,%s&z=16', $data['longitude'], $data['latitude']);
        } elseif (isset($data['address'])) {
            $mapLink = sprintf('https://yandex.ru/maps/?text=%s', urlencode($data['address']));
        } else {
            $response->getBody()->write(json_encode(['error' => 'Необходимо указать адрес или координаты']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        
        $response->getBody()->write(json_encode(['yandexMapLink' => $mapLink]));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
