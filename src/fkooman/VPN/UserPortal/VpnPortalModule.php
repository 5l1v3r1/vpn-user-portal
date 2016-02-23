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

use BaconQrCode\Renderer\Image\Png;
use BaconQrCode\Writer;
use fkooman\Http\Exception\BadRequestException;
use fkooman\Http\RedirectResponse;
use fkooman\Http\Request;
use fkooman\Http\Response;
use fkooman\Rest\Plugin\Authentication\UserInfoInterface;
use fkooman\Rest\Service;
use fkooman\Rest\ServiceModuleInterface;
use fkooman\Tpl\TemplateManagerInterface;
use fkooman\IO\IO;

class VpnPortalModule implements ServiceModuleInterface
{
    /** @var \fkooman\Tpl\TemplateManagerInterface */
    private $templateManager;

    /** @var VpnConfigApiClient */
    private $vpnConfigApiClient;

    /** @var VpnServerApiClient */
    private $vpnServerApiClient;

    /** @var ApiDb */
    private $apiDb;

    /** @var fkooman\IO\IO */
    private $io;

    /** @var string */
    private $companionAppUrl;

    public function __construct(TemplateManagerInterface $templateManager, VpnConfigApiClient $vpnConfigApiClient, VpnServerApiClient $vpnServerApiClient, ApiDb $apiDb, IO $io = null)
    {
        $this->templateManager = $templateManager;
        $this->vpnConfigApiClient = $vpnConfigApiClient;
        $this->vpnServerApiClient = $vpnServerApiClient;
        $this->apiDb = $apiDb;
        if (is_null($io)) {
            $io = new IO();
        }
        $this->io = $io;
        $this->companionAppUrl = null;
    }

    public function setCompanionAppUrl($companionAppUrl)
    {
        $this->companionAppUrl = $companionAppUrl;
    }

    public function init(Service $service)
    {
        $noAuth = array(
            'fkooman\Rest\Plugin\Authentication\AuthenticationPlugin' => array(
                'enabled' => false,
            ),
        );

        $userAuth = array(
            'fkooman\Rest\Plugin\Authentication\AuthenticationPlugin' => array(
                'activate' => array('user'),
            ),
        );

        /* REDIRECTS **/
        $service->get(
            '/config/',
            function (Request $request) {
                return new RedirectResponse($request->getUrl()->getRootUrl(), 301);
            },
            $noAuth
        );

        $service->get(
            '/',
            function (Request $request) {
                return new RedirectResponse($request->getUrl()->getRootUrl().'home', 302);
            },
            $noAuth
        );

        $service->get(
            '/home',
            function (Request $request, UserInfoInterface $u) {
                return $this->templateManager->render(
                    'vpnPortalHome',
                    array(
                    )
                );
            },
            $userAuth
        );

        $service->get(
            '/new',
            function (Request $request, UserInfoInterface $u) {
                return $this->templateManager->render(
                    'vpnPortalNew',
                    array(
                        'advanced' => (bool) $request->getUrl()->getQueryParameter('advanced'),
                        'cnLength' => 63 - strlen($u->getUserId()),
                    )
                );
            },
            $userAuth
        );

        $service->post(
            '/new',
            function (Request $request, UserInfoInterface $u) {
                $configName = $request->getPostParameter('name');
                $type = $request->getPostParameter('type');

                return $this->getConfig($request, $u->getUserId(), $configName, $type);
            },
            $userAuth
        );

        $service->get(
            '/configurations',
            function (Request $request, UserInfoInterface $u) {
                $certList = $this->vpnConfigApiClient->getCertList($u->getUserId());
                $disabledCommonNames = $this->vpnServerApiClient->getCcdDisable($u->getUserId());

                $activeVpnConfigurations = array();
                $revokedVpnConfigurations = array();
                $disabledVpnConfigurations = array();
                $expiredVpnConfigurations = array();

                foreach ($certList['items'] as $c) {
                    if ('E' === $c['state']) {
                        $expiredVpnConfigurations[] = $c;
                    } elseif ('R' === $c['state']) {
                        $revokedVpnConfigurations[] = $c;
                    } elseif ('V' === $c['state']) {
                        $commonName = $u->getUserId().'_'.$c['name'];
                        if (in_array($commonName, $disabledCommonNames['disabled'])) {
                            $disabledVpnConfigurations[] = $c;
                        } else {
                            $activeVpnConfigurations[] = $c;
                        }
                    }
                }

                return $this->templateManager->render(
                    'vpnPortalConfigurations',
                    array(
                        'activeVpnConfigurations' => $activeVpnConfigurations,
                        'disabledVpnConfigurations' => $disabledVpnConfigurations,
                        'revokedVpnConfigurations' => $revokedVpnConfigurations,
                        'expiredVpnConfigurations' => $expiredVpnConfigurations,
                    )
                );
            },
            $userAuth
        );

        $service->post(
            '/revoke',
            function (Request $request, UserInfoInterface $u) {
                $configName = $request->getPostParameter('name');
                $formConfirm = $request->getPostParameter('confirm');

                if (is_null($formConfirm)) {
                    // ask for confirmation
                    return $this->templateManager->render(
                        'vpnPortalConfirmRevoke',
                        array(
                            'configName' => $configName,
                        )
                    );
                }

                if ('yes' === $formConfirm) {
                    // user said yes
                    $this->revokeConfig($u->getUserId(), $configName);
                }

                return new RedirectResponse($request->getUrl()->getRootUrl().'configurations', 302);
            },
            $userAuth
        );

        $service->get(
            '/whoami',
            function (Request $request, UserInfoInterface $u) {
                $response = new Response(200, 'text/plain');
                $response->setBody($u->getUserId());

                return $response;
            },
            $userAuth
        );

        $service->get(
            '/documentation',
            function (Request $request, UserInfoInterface $u) {
                return $this->templateManager->render(
                    'vpnPortalDocumentation',
                    array(
                    )
                );
            },
            $userAuth
        );
    }

    private function getConfig(Request $request, $userId, $configName, $type)
    {
        Utils::validateConfigName($configName);

        // userId + configName length cannot be longer than 64 as the
        // certificate CN cannot be longer than 64
        if (64 < strlen($userId) + strlen($configName) + 1) {
            throw new BadRequestException(
                sprintf('commonName length MUST not exceed %d', 63 - strlen($userId))
            );
        }

        // make sure the configuration does not exist yet
        // XXX: this should be optimized a bit...
        $certList = $this->vpnConfigApiClient->getCertList($userId);
        foreach ($certList['items'] as $cert) {
            if ($configName === $cert['name']) {
                return $this->templateManager->render(
                    'vpnPortalErrorConfigExists',
                    array(
                        'configName' => $configName,
                    )
                );
            }
        }

        switch ($type) {
            case 'zip':
                // return a ZIP file    
                $configData = $this->vpnConfigApiClient->addConfiguration($userId, $configName);
                $configData = Utils::configToZip($configName, $configData);
                $response = new Response(200, 'application/zip');
                $response->setHeader('Content-Disposition', sprintf('attachment; filename="%s.zip"', $configName));
                $response->setBody($configData);
                break;
            case 'ovpn':
                // return an OVPN file
                $configData = $this->vpnConfigApiClient->addConfiguration($userId, $configName);
                $response = new Response(200, 'application/x-openvpn-profile');
                $response->setHeader('Content-Disposition', sprintf('attachment; filename="%s.ovpn"', $configName));
                $response->setBody($configData);
                break;
            case 'app':
                // show a QR code for use in App
                $apiCredentials = $this->generateApiCredentials($userId);
                $qrUrl = sprintf(
                    '%sapi/config?userName=%s&userPass=%s&configName=%s',
                    $request->getUrl()->getRootUrl(),
                    $apiCredentials['userName'],
                    $apiCredentials['userPass'],
                    $configName
                );

                $renderer = new Png();
                $renderer->setHeight(256);
                $renderer->setWidth(256);
                $writer = new Writer($renderer);
                $qrData = base64_encode($writer->writeString($qrUrl));

                return $this->templateManager->render(
                    'vpnPortalApp',
                    array(
                        'qrCode' => $qrData,
                        'companionAppUrl' => $this->companionAppUrl,
                    )
                );

            default:
                throw new BadRequestException('invalid type');
        }

        return $response;
    }

    private function revokeConfig($userId, $configName)
    {
        Utils::validateConfigName($configName);

        $this->vpnConfigApiClient->revokeConfiguration($userId, $configName);

        // trigger a CRL reload in the servers
        $this->vpnServerApiClient->postCrlFetch();

        // disconnect the client
        $this->vpnServerApiClient->postKill(sprintf('%s_%s', $userId, $configName));
    }

    private function generateApiCredentials($userId)
    {
        // delete API credentials if they exist for this user
        $this->apiDb->deleteKey($userId);

        // generate new credentials
        $userName = $this->io->getRandom(8);
        $userPass = $this->io->getRandom(8);
        $userPassHash = password_hash($userPass, PASSWORD_DEFAULT);
        $this->apiDb->addKey($userId, $userName, $userPassHash);

        return array(
            'userName' => $userName,
            'userPass' => $userPass,
        );
    }
}
