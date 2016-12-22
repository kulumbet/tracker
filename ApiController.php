<?php

namespace app\controllers;

use Yii;
use yii\web\Controller;
use yii\web\Response;
use app\models\Edge;
use app\models\Route;
use app\models\Busstop;
use app\models\Routestop;

class ApiController extends Controller
{
    const BUS_ROUTE_URL  = '';
    const BUS_COORDINATION_URL = '';

    public function actionIndex()
    {
    }

    public function actionRoute()
    {
        $routes = $this->curlRequest(BUS_ROUTE_URL);

        if (!empty($routes))
        {
            Route::deleteAll();
            Yii::$app->db->createCommand('ALTER TABLE routes AUTO_INCREMENT = 1')->query();

            $api_data = substr($routes, 2, -2);
            $arr = explode('},{',$api_data);

            foreach ($arr as $data)
            {
                $bus = substr($data,5,4);
                $route = explode(" ",substr($data,18, -1));
                $clean_route = $route[0];

                if (is_numeric($clean_route)) {
                    $model = new Route();
                    $model->bus = $bus;
                    $model->route = $clean_route;

                    $model->insert();
                }
            }
        }
    }

    public function actionCoordinates()
    {
        $coordinates = $this->curlRequest(BUS_COORDINATION_URL);

        $cache = Yii::$app->cache;
        $cachedData  = $cache->get('coordinates');

        if (strlen($coordinates) > 100 && $cachedData != $coordinates)
        {
            $cache->set('coordinates', $coordinates);
            $coordinates = json_decode($coordinates, true);

            $routes = Route::find()->all();
            foreach ($routes as $route) {
                $key = array_search($route->bus, array_column($coordinates, 0));

                if (isset($key)) {
                    (isset($coordinates[$key][1]['t'])) ? $time = $coordinates[$key][1]['t'] : $time = '0000-00-00 00:00:00';
                    (isset($coordinates[$key][1]['y'])) ? $latitude = $coordinates[$key][1]['y'] : $latitude = 0;
                    (isset($coordinates[$key][1]['x'])) ? $longitude = $coordinates[$key][1]['x'] : $longitude = 0;

                    $angle = (rad2deg(atan2(
                            sin(deg2rad($longitude) - deg2rad($data->longitude)) * cos(deg2rad($latitude)),
                            cos(deg2rad($data->latitude)) * sin(deg2rad($latitude))
                            - sin(deg2rad($data->latitude)) * cos(deg2rad($latitude)) * cos(deg2rad($longitude) - deg2rad($data->longitude))
                        )) + 360) % 360;

                    $route->time = $time;
                    $route->latitude = $latitude;
                    $route->longitude = $longitude;
                    if ($angle != 0) {
                        $route->angle = $angle;
                    }

                    $route->update();
                }
            }

            $cache->set('buscoordinates', $routes);
        }
    }

    public function actionBusCoordinates($id = null, $id1 = null, $id2 = null, $id3 = null, $id4 = null)
    {
        $response = array();
        $cache = Yii::$app->cache;

        if (isset($id)) {
            $routesArr = array($id, $id1, $id2, $id3, $id4);
            $routes = $cache->get('buscoordinates');

            $routes = $routes[0]->findAll(['route' => $routesArr]);
        } else {
            $routes = $cache->get('buscoordinates');
        }

        $current_time = time();
        foreach ($routes as $route) {
            $minutediff = ceil(abs($current_time - strtotime($route->time)) / 60);
            if ($minutediff <= 20) {
                $response[$route->bus]['route'] = $route->route;
                $response[$route->bus]['time'] = $route->time;
                $response[$route->bus]['latitude'] = $route->latitude;
                $response[$route->bus]['longitude'] = $route->longitude;
                $response[$route->bus]['angle'] = $route->angle;
            }
        }

        $this->sendResponse($response);
    }

    // routes from stop A to stop B, and their distances
    public function actionFindRoute($stopA, $stopB)
    {
        $routesA = Busstop::find()->select(['`passing_routes`'])->where(['code' => $stopA])->one();
        $routesB = Busstop::find()->select(['`passing_routes`'])->where(['code' => $stopB])->one();

        $passingRoutesA = array();
        $passingRoutes = explode(',', $routesA['passing_routes']);
        foreach ($passingRoutes as $passingRoute) {
            $passingRoutesA[] = trim($passingRoute);
        }

        $passingRoutesB = array();
        $passingRoutes = explode(',', $routesB['passing_routes']);
        foreach ($passingRoutes as $passingRoute) {
            $passingRoutesB[] = trim($passingRoute);
        }

        $commonRoutes = array_intersect($passingRoutesA, $passingRoutesB);

        $response = array();
        $i = 0;
        foreach ($commonRoutes as $commonRoute)
        {
            $routeStops = Routestop::find()
                ->where(['number' => $commonRoute])
                ->andWhere(['direction' => 1])
                ->all();

            $distance = 0;
            $routeFound = $busStopAfound = false;
            foreach ($routeStops as $routeStop) {
                if ($routeStop['stop_code'] == $stopB) {
                    if ($busStopAfound) {
                        $routeFound = true;
                    }
                    break;
                } elseif ($routeStop['stop_code'] == $stopA || $busStopAfound) {
                    $busStopAfound = true;
                    $distance++;
                }
            }

            if ($routeFound)
            {
                $response[$i]['route'] = $commonRoute;
                $response[$i]['stopNumbers'] = $distance;
                $i++;

                continue;
            }
            else
            {
                $routeStops = Routestop::find()
                    ->where(['number' => $commonRoute])
                    ->andWhere(['direction' => 2])
                    ->all();

                foreach ($routeStops as $routeStop) {
                    if ($routeStop['stop_code'] == $stopB) {
                        break;
                    } elseif ($routeStop['stop_code'] == $stopA || $busStopAfound) {
                        $busStopAfound = true;
                        $distance++;
                    }
                }

                $response[$i]['route'] = $commonRoute;
                $response[$i]['stopNumbers'] = $distance;
                $i++;
            }
        }

        $this->sendResponse($response);
    }

    public function actionFindRouteByPoints($$latitudeA, $longitudeA, $latitudeB, $longitudeB) {
        $nearbyBusStopsA = $this->getNearbyBusStops($$latitudeA, $longitudeA);
        $nearbyBusStopsB = $this->getNearbyBusStops($latitudeB, $longitudeB);

        $passingRoutesA = array();
        $codesA = array();
        foreach ($nearbyBusStopsA as $nearbyBusStopA) {
            $routesA = Busstop::find()->select(['`passing_routes`'])->where(['code' => $nearbyBusStopA])->one();

            $passingRoutesA = array();
            $passingRoutes = explode(',', $routesA['passing_routes']);
            foreach ($passingRoutes as $passingRoute)
            {
                $route = trim($passingRoute);
                if (in_array($route, $passingRoutesA)) continue;
                else {
                    $passingRoutesA[] = $route;
                    $codesA[$route] = $nearbyBusStopA;
                }
            }
        }

        $passingRoutesB = array();
        $codesB = array();
        foreach ($nearbyBusStopsB as $nearbyBusStopB) {
            $routesB = Busstop::find()->select(['`passing_routes`'])->where(['code' => $nearbyBusStopB])->one();

            $passingRoutesB = array();
            $passingRoutes = explode(',', $routesB['passing_routes']);
            foreach ($passingRoutes as $passingRoute)
            {
                $route = trim($passingRoute);
                if (in_array($route, $passingRoutesB)) continue;
                else {
                    $passingRoutesB[] = $route;
                    $codesB[$route] = $nearbyBusStopB;
                }
            }
        }

        $commonRoutes = array_intersect($passingRoutesA, $passingRoutesB);
        $response = array();

        $c = 0;
        foreach ($commonRoutes as $commonRoute) {
            $response[$c]['route'] = $commonRoute;
            $response[$c]['from'] = $codesA[$commonRoute];
            $response[$c]['to'] = $codesB[$commonRoute];
            $c++;            
        }

        $this->sendResponse($response);
    }

    public function getNearbyBusStops($latitude, $longitude)
    {
        $nearestBusStops = array();

        $busStops = Busstop::find()->all();
        foreach ($busStops as $busStop) {
            $distance = $this->calcDistance($latitude, $longitude, $busStop->latitude, $busStop->longitude);

            if ($distance < 500) {
                $nearestBusStops[] = $busStop->code;
            }
        }

        return $nearestBusStops;
    }

    // distance in meters
    public function calcDistance($$latitudeA, $longitudeA, $latitudeB, $longitudeB)
    {
        $theta = $longitudeA - $longitudeB;
        $dist = sin(deg2rad($$latitudeA)) * sin(deg2rad($latitudeB))
                + cos(deg2rad($$latitudeA)) * cos(deg2rad($latitudeB)) * cos(deg2rad($theta));
        $dist = acos($dist);
        $dist = rad2deg($dist);
        $dist = $dist * 60 * 1852;

        return $dist;
    }

    public function curlRequest($url)
    {
        $ch = curl_init();

        $timeout = 5;
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
        $data = curl_exec($ch);
        curl_close($ch);

        return $data;
    }

    private function sendResponse($content, $status = 200)
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        Yii::$app->response->statusCode = $status;

        echo json_encode($content);

        Yii::$app->end();
    }
}
