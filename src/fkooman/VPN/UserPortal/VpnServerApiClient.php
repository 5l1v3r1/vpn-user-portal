<?php
/**
 * Copyright 2016 François Kooman <fkooman@tuxed.net>.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace fkooman\VPN\UserPortal;

use GuzzleHttp\Client;

class VpnServerApiClient extends VpnApiClient
{
    /** @var string */
    private $vpnServerApiUri;

    public function __construct(Client $client, $vpnServerApiUri)
    {
        parent::__construct($client);
        $this->vpnServerApiUri = $vpnServerApiUri;
    }

    public function getConfig($userId)
    {
        $requestUri = sprintf('%s/config/?user_id=%s', $this->vpnServerApiUri, $userId);

        return $this->exec('GET', $requestUri);
    }

    public function postKill($commonName)
    {
        $requestUri = sprintf('%s/openvpn/kill', $this->vpnServerApiUri);

        return $this->exec(
            'POST',
            $requestUri,
            [
                'body' => [
                    'common_name' => $commonName,
                ],
            ]
        );
    }

    public function postCrlFetch()
    {
        $requestUri = sprintf('%s/ca/crl/fetch', $this->vpnServerApiUri);

        return $this->exec('POST', $requestUri);
    }

    public function getInfo()
    {
        $requestUri = sprintf('%s/info', $this->vpnServerApiUri);

        return $this->exec('GET', $requestUri);
    }
}
