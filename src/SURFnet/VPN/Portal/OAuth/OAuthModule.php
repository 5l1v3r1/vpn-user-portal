<?php
/**
 *  Copyright (C) 2016 SURFnet.
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU Affero General Public License as
 *  published by the Free Software Foundation, either version 3 of the
 *  License, or (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU Affero General Public License for more details.
 *
 *  You should have received a copy of the GNU Affero General Public License
 *  along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */
namespace SURFnet\VPN\Portal\OAuth;

use SURFnet\VPN\Common\Http\Exception\HttpException;
use SURFnet\VPN\Common\Http\Service;
use SURFnet\VPN\Common\Http\Request;
use SURFnet\VPN\Common\Http\HtmlResponse;
use SURFnet\VPN\Common\Http\RedirectResponse;
use SURFnet\VPN\Common\TplInterface;

class OAuthModule
{
    /** @var \SURFnet\VPN\Common\TplInterface */
    private $tpl;

    /** @var TokenStorage */
    private $tokenStorage;

    /** @var ClientConfig */
    private $clientConfig;

    public function __construct(TplInterface $tpl, TokenStorage $tokenStorage, ClientConfig $clientConfig)
    {
        $this->tpl = $tpl;
        $this->tokenStorage = $tokenStorage;
        $this->clientConfig = $clientConfig;
    }

    public function init(Service $service)
    {
        $this->get(
            '/authorize',
            function (Request $request, array $hookData) {
                $userId = $hookData['auth'];

                $this->validateClient($request);

                // ask for approving this client/scope
                return new HtmlResponse(
                    $this->tpl->render(
                        'authorizeOAuthClient',
                        [
                            'client_id' => $request->getQueryParameter('client_id'),
                            'scope' => $request->getQueryParameter('scope'),
                            'redirect_uri' => $request->getQueryParameter('redirect_uri'),
                        ]
                    )
                );
            }
        );

        $this->post(
            '/authorize',
            function (Request $request, array $hookData) {
                $userId = $hookData['auth'];

                $this->validateClient($request);

                if ('no' === $request->getPostParameter('approve')) {
                    $redirectQuery = [
                        'error' => 'XXX',       // XXX check OAuth RFC for exact error code
                        'state' => $request->getQueryParameter('state'),
                    ];

                    $redirectUri = sprintf('%s#%s', $request->getQueryParameter('redirect_uri'), $redirectQuery);

                    return new RedirectResponse($redirectUri, 302);
                }

                // store access_token
                // XXX use random class with interface for better testing
                $accessToken = bin2hex(random_bytes(16));

                $this->db->storeAccessToken(
                    $accessToken,
                    $request->getQueryParameter('client_id'),
                    $request->getQueryParameter('scope'),
                    $userId
                );

                // add state, access_token to redirect_uri
                $redirectQuery = http_build_query(
                    [
                        'access_token' => $accessToken,
                        'state' => $request->getQueryParameter('state'),
                    ]
                );

                $redirectUri = sprintf('%s#%s', $request->getQueryParameter('redirect_uri'), $redirectQuery);

                return new RedirectResponse($redirectUri, 302);
            }
        );
    }

    private function validateClient(Request $request)
    {
        // all parameters are required
        // XXX input validate the parameters
        $clientId = $request->getQueryParameter('client_id');
        $redirectUri = $request->getQueryParameter('redirect_uri');
        $responseType = $request->getQueryParameter('response_type');
        $scope = $request->getQueryParameter('scope');
        $state = $request->getQueryParameter('state');

        if ('token' !== $responseType) {
            throw new HttpException('only "token" response_type supported', 400);
        }

        // check if we have a client with this clientId and redirectUri
        if (false === $this->clientConfig->exists($clientId, $redirectUri)) {
            throw new HttpException('client with this client_id and redirect_uri are not registered', 400);
        }
    }
}