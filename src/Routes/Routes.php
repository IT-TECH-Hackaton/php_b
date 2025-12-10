<?php

namespace App\Routes;

use App\Handlers\AuthHandler;
use App\Handlers\EventHandler;
use App\Handlers\UserHandler;
use App\Handlers\AdminHandler;
use App\Handlers\UploadHandler;
use App\Handlers\HealthHandler;
use App\Handlers\ReviewHandler;
use App\Handlers\GeocoderHandler;
use App\Handlers\InterestHandler;
use App\Handlers\MatchingHandler;
use App\Handlers\CommunityHandler;
use App\Handlers\CategoryHandler;
use App\Middleware\AuthMiddleware;
use App\Middleware\OptionalAuthMiddleware;
use App\Middleware\AdminMiddleware;
use App\Middleware\RateLimitMiddleware;
use Slim\App;

class Routes
{
    public static function setup(App $app): void
    {
        $app->get('/health', [HealthHandler::class, 'healthCheck']);

        $api = $app->group('/api', function ($group) {
            $auth = $group->group('/auth', function ($group) {
                $group->post('/register', [AuthHandler::class, 'register'])->addMiddleware(new RateLimitMiddleware('3-H'));
                $group->post('/verify-email', [AuthHandler::class, 'verifyEmail'])->addMiddleware(new RateLimitMiddleware('10-M'));
                $group->post('/resend-code', [AuthHandler::class, 'resendCode'])->addMiddleware(new RateLimitMiddleware('3-H'));
                $group->post('/login', [AuthHandler::class, 'login'])->addMiddleware(new RateLimitMiddleware('5-M'));
                $group->post('/logout', [AuthHandler::class, 'logout']);
                $group->post('/forgot-password', [AuthHandler::class, 'forgotPassword'])->addMiddleware(new RateLimitMiddleware('3-H'));
                $group->post('/reset-password', [AuthHandler::class, 'resetPassword'])->addMiddleware(new RateLimitMiddleware('5-M'));
                $group->get('/yandex', [AuthHandler::class, 'yandexAuth'])->addMiddleware(new RateLimitMiddleware('10-M'));
                $group->get('/yandex/callback', [AuthHandler::class, 'yandexCallback']);
                $group->post('/yandex/fake', [AuthHandler::class, 'fakeYandexAuth'])->addMiddleware(new RateLimitMiddleware('10-M'));
                $group->post('/init-admin', [AuthHandler::class, 'initDefaultAdmin'])->addMiddleware(new RateLimitMiddleware('1-H'));
            });

            $upload = $group->group('/upload', function ($group) {
                $group->post('/image', [UploadHandler::class, 'uploadImage']);
            });
            $upload->addMiddleware(new AuthMiddleware());
            $upload->addMiddleware(new RateLimitMiddleware('20-H'));

            $user = $group->group('/user', function ($group) {
                $group->get('/profile', [UserHandler::class, 'getProfile']);
                $group->put('/profile', [UserHandler::class, 'updateProfile']);
            });
            $user->addMiddleware(new AuthMiddleware());

            $events = $group->group('/events', function ($group) {
                $group->get('', [EventHandler::class, 'getEvents'])->addMiddleware(new OptionalAuthMiddleware());
                $group->get('/{id}', [EventHandler::class, 'getEvent'])->addMiddleware(new OptionalAuthMiddleware());
                $group->post('', [EventHandler::class, 'createEvent'])->addMiddleware(new AuthMiddleware());
                $group->put('/{id}', [EventHandler::class, 'updateEvent'])->addMiddleware(new AuthMiddleware());
                $group->delete('/{id}', [EventHandler::class, 'deleteEvent'])->addMiddleware(new AuthMiddleware());
                $group->post('/{id}/join', [EventHandler::class, 'joinEvent'])->addMiddleware(new AuthMiddleware());
                $group->delete('/{id}/leave', [EventHandler::class, 'leaveEvent'])->addMiddleware(new AuthMiddleware());
                $group->get('/{id}/export', [EventHandler::class, 'exportParticipants'])->addMiddleware(new AuthMiddleware());
            });

            $reviews = $group->group('/events/{id}/reviews', function ($group) {
                $group->get('', [ReviewHandler::class, 'getEventReviews']);
                $group->post('', [ReviewHandler::class, 'createReview']);
                $group->put('/{reviewId}', [ReviewHandler::class, 'updateReview']);
                $group->delete('/{reviewId}', [ReviewHandler::class, 'deleteReview']);
            });
            $reviews->addMiddleware(new AuthMiddleware());

            $geocoder = $group->group('/geocoder', function ($group) {
                $group->post('/geocode', [GeocoderHandler::class, 'geocodeAddress']);
                $group->post('/reverse', [GeocoderHandler::class, 'reverseGeocode']);
                $group->post('/map-link', [GeocoderHandler::class, 'generateMapLink']);
            });
            $geocoder->addMiddleware(new RateLimitMiddleware('100-H'));

            $interests = $group->group('/interests', function ($group) {
                $group->get('', [InterestHandler::class, 'getInterests']);
                $group->get('/categories', [InterestHandler::class, 'getCategories']);
                $group->post('', [InterestHandler::class, 'createInterest'])->addMiddleware(new AuthMiddleware());
                $group->get('/my', [InterestHandler::class, 'getUserInterests'])->addMiddleware(new AuthMiddleware());
                $group->post('/my', [InterestHandler::class, 'addUserInterest'])->addMiddleware(new AuthMiddleware());
                $group->delete('/my/{id}', [InterestHandler::class, 'removeUserInterest'])->addMiddleware(new AuthMiddleware());
                $group->put('/my/{id}/weight', [InterestHandler::class, 'updateUserInterestWeight'])->addMiddleware(new AuthMiddleware());
            });

            $matching = $group->group('/events/{id}/matching', function ($group) {
                $group->post('', [MatchingHandler::class, 'createEventMatching']);
                $group->get('', [MatchingHandler::class, 'getMatches']);
                $group->delete('', [MatchingHandler::class, 'removeEventMatching']);
                $group->post('/request', [MatchingHandler::class, 'createMatchRequest']);
                $group->get('/requests', [MatchingHandler::class, 'getMyMatchRequests']);
                $group->post('/requests/{id}/accept', [MatchingHandler::class, 'acceptMatchRequest']);
                $group->post('/requests/{id}/reject', [MatchingHandler::class, 'rejectMatchRequest']);
            });
            $matching->addMiddleware(new AuthMiddleware());

            $communities = $group->group('/communities', function ($group) {
                $group->get('', [CommunityHandler::class, 'getCommunities']);
                $group->get('/{id}', [CommunityHandler::class, 'getCommunity']);
                $group->get('/{id}/members', [CommunityHandler::class, 'getCommunityMembers']);
                $group->post('', [CommunityHandler::class, 'createCommunity'])->addMiddleware(new AuthMiddleware());
                $group->post('/{id}/join', [CommunityHandler::class, 'joinCommunity'])->addMiddleware(new AuthMiddleware());
                $group->delete('/{id}/leave', [CommunityHandler::class, 'leaveCommunity'])->addMiddleware(new AuthMiddleware());
                $group->get('/my', [CommunityHandler::class, 'getMyCommunities'])->addMiddleware(new AuthMiddleware());
            });

            $admin = $group->group('/admin', function ($group) {
                $adminUsers = $group->group('/users', function ($group) {
                    $group->post('', [AdminHandler::class, 'createUser']);
                    $group->get('', [AdminHandler::class, 'getUsers']);
                    $group->get('/export', [AdminHandler::class, 'exportUsers']);
                    $group->get('/{id}', [AdminHandler::class, 'getUser']);
                    $group->put('/{id}', [AdminHandler::class, 'updateUser']);
                    $group->post('/{id}/reset-password', [AdminHandler::class, 'resetUserPassword']);
                    $group->delete('/{id}', [AdminHandler::class, 'deleteUser']);
                });

                $adminEvents = $group->group('/events', function ($group) {
                    $group->get('', [AdminHandler::class, 'getAdminEvents']);
                });

                $adminCategories = $group->group('/categories', function ($group) {
                    $group->get('', [CategoryHandler::class, 'getCategories']);
                    $group->post('', [CategoryHandler::class, 'createCategory']);
                    $group->put('/{id}', [CategoryHandler::class, 'updateCategory']);
                    $group->delete('/{id}', [CategoryHandler::class, 'deleteCategory']);
                });
            });
            $admin->addMiddleware(new AuthMiddleware());
            $admin->addMiddleware(new AdminMiddleware());

            $categories = $group->group('/categories', function ($group) {
                $group->get('', [CategoryHandler::class, 'getCategories']);
            });
        });
    }
}
