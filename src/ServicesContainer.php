<?php

namespace GlobalPayments\Api;

use GlobalPayments\Api\Gateways\Gp3DSProvider;
use GlobalPayments\Api\Gateways\IPaymentGateway;
use GlobalPayments\Api\Gateways\IRecurringService;
use GlobalPayments\Api\Gateways\PayPlanConnector;
use GlobalPayments\Api\Gateways\PorticoConnector;
use GlobalPayments\Api\Gateways\RealexConnector;
use GlobalPayments\Api\Gateways\ISecure3dProvider;
use GlobalPayments\Api\Entities\Enums\Environment;
use GlobalPayments\Api\Entities\Enums\GatewayProvider;
use GlobalPayments\Api\Entities\Enums\Secure3dVersion;
use GlobalPayments\Api\Entities\Enums\ServiceEndpoints;
use GlobalPayments\Api\Entities\Exceptions\ConfigurationException;
use GlobalPayments\Api\Gateways\MerchantwareConnector;
use GlobalPayments\Api\Gateways\TransITConnector;

class ServicesContainer
{
    /** @var  array */
    private $secure3dProviders;
    /** @var IPaymentGateway */
    private $gateway;
    /** @var IRecurringService */
    private $recurring;
    /** @var ServicesContainer */
    private static $instance;

    /** @return ISecure3dProvider */
    private function getSecure3dProvider($version)
    {
        if (!empty($this->secure3dProviders[$version])) {
            return $this->secure3dProviders[$version];
        } elseif ($version == Secure3dVersion::ANY) {
            $provider = $this->secure3dProviders[Secure3dVersion::TWO];
            if ($provider == null) {
                $provider = $this->secure3dProviders[Secure3dVersion::ONE];
            }
            return $provider;
        }
        return null;
    }

    /** @return void */
    private function setSecure3dProvider($version, ISecure3dProvider $provider)
    {
        $this->secure3dProviders[$version] = $provider;
    }

    /**
     * ServicesContainer constructor.
     *
     * @param IGateway $gateway
     *
     * @return
     */
    public function __construct(IPaymentGateway $gateway, IRecurringService $recurring = null)
    {
        $this->gateway = $gateway;
        $this->recurring = $recurring;
    }

    /**
     * Gets the current `ServicesContainer` instance
     *
     * @return ServicesContainer
     */
    public static function instance()
    {
        if (static::$instance == null) {
            static::$instance = new static(null);
        }
        return static::$instance;
    }

    /**
     * Configures the `ServicesContainer` singleton
     *
     * @param ServicesConfig $config
     *
     * @return void
     */
    public static function configure(ServicesConfig $config)
    {
        $config->validate();

        $gateway = null;

        // GP ECOM
        if ($config->gatewayProvider === GatewayProvider::GP_ECOM) {
            if (empty($config->serviceUrl)) {
                if ($config->environment === Environment::TEST) {
                    $config->serviceUrl = ServiceEndpoints::GLOBAL_ECOM_TEST;
                } else {
                    $config->serviceUrl = ServiceEndpoints::GLOBAL_ECOM_PRODUCTION;
                }
            }

            $gateway = new RealexConnector();
            $gateway->accountId = $config->accountId;
            $gateway->channel = $config->channel;
            $gateway->merchantId = $config->merchantId;
            $gateway->rebatePassword = $config->rebatePassword;
            $gateway->refundPassword = $config->refundPassword;
            $gateway->sharedSecret = $config->sharedSecret;
            $gateway->timeout = $config->timeout;
            $gateway->serviceUrl = $config->serviceUrl;
            $gateway->hostedPaymentConfig = $config->hostedPaymentConfig;
            $gateway->curlOptions = $config->curlOptions;
            static::$instance = new static($gateway, $gateway);
            // set default
            if ($config->secure3dVersion == null) {
                $config->secure3dVersion = Secure3dVersion::ONE;
            }

            // secure 3d v1
            if ($config->secure3dVersion === Secure3dVersion::ONE || $config->secure3dVersion === Secure3dVersion::ANY) {
                static::$instance->setSecure3dProvider(Secure3dVersion::ONE, $gateway);
            }

            // secure 3d v2
            if ($config->secure3dVersion === Secure3dVersion::TWO || $config->secure3dVersion === Secure3dVersion::ANY) {
                $secure3d2 = new Gp3DSProvider();
                $secure3d2->setMerchantId($config->merchantId);
                $secure3d2->setAccountId($config->accountId);
                $secure3d2->setSharedSecret($config->sharedSecret);
                $secure3d2->serviceUrl = $config->environment == Environment::TEST ? ServiceEndpoints::THREE_DS_AUTH_TEST : ServiceEndpoints::THREE_DS_AUTH_PRODUCTION;
                $secure3d2->setMerchantContactUrl($config->merchantContactUrl);
                $secure3d2->setMethodNotificationUrl($config->methodNotificationUrl);
                $secure3d2->setChallengeNotificationUrl($config->challengeNotificationUrl);
                $secure3d2->timeout = $config->timeout;

                static::$instance->setSecure3dProvider(Secure3dVersion::TWO, $secure3d2);
            }
        // Genius
        } elseif ($config->gatewayProvider === GatewayProvider::GENIUS) {
            if (empty($config->serviceUrl)) {
                if ($config->environment === Environment::TEST) {
                    $config->serviceUrl = ServiceEndpoints::MERCHANTWARE_TEST;
                } else {
                    $config->serviceUrl = ServiceEndpoints::MERCHANTWARE_PRODUCTION;
                }
            }

            $gateway = new MerchantwareConnector();
            $gateway->merchantName = $config->merchantName;
            $gateway->merchantSiteId = $config->merchantSiteId;
            $gateway->merchantKey = $config->merchantKey;
            $gateway->registerNumber = $config->registerNumber;
            $gateway->terminalId = $config->terminalId;
            $gateway->timeout = $config->timeout;
            $gateway->serviceUrl = $config->serviceUrl;

            static::$instance = new static($gateway);
        // TransIT
        } elseif ($config->gatewayProvider === GatewayProvider::TRANSIT) {
            if (empty($config->serviceUrl)) {
                if ($config->environment === Environment::TEST) {
                    $config->serviceUrl = ServiceEndpoints::TRANSIT_TEST;
                } else {
                    $config->serviceUrl = ServiceEndpoints::TRANSIT_PRODUCTION;
                }
            }

            $gateway = new TransITConnector();
            $gateway->deviceId = $config->deviceId;
            $gateway->merchantId = $config->merchantId;
            $gateway->transactionKey = $config->transactionKey;
            $gateway->manifest = $config->manifest;
            $gateway->userId = $config->username;
            $gateway->password = $config->password;
            $gateway->developerId = $config->developerId;
            $gateway->timeout = $config->timeout;
            $gateway->serviceUrl = $config->serviceUrl;
            $gateway->acceptorConfig = $config->acceptorConfig;
            
            static::$instance = new static($gateway);
        } else {
            if (empty($config->serviceUrl) && !empty($config->secretApiKey)) {
                $env = explode('_', $config->secretApiKey)[1];
                if ($env == "prod") {
                    $config->serviceUrl = ServiceEndpoints::PORTICO_PRODUCTION;
                } else {
                    $config->serviceUrl = ServiceEndpoints::PORTICO_TEST;
                }
            }

            $gateway = new PorticoConnector();
            $gateway->siteId = $config->siteId;
            $gateway->licenseId = $config->licenseId;
            $gateway->deviceId = $config->deviceId;
            $gateway->username = $config->username;
            $gateway->password = $config->password;
            $gateway->secretApiKey = $config->secretApiKey;
            $gateway->developerId = $config->developerId;
            $gateway->versionNumber = $config->versionNumber;
            $gateway->timeout = $config->timeout;
            $gateway->serviceUrl = $config->serviceUrl . '/Hps.Exchange.PosGateway/PosGatewayService.asmx';
            $gateway->curlOptions = $config->curlOptions;

            static::$instance = new static($gateway);

            if (!empty($config->dataClientId)) {
                // TODO: Add data services connector class and code
            } else {
                static::$instance = new static($gateway);
            }
            
            $recurring = new PayPlanConnector();
            $recurring->secretApiKey = $config->secretApiKey;
            $recurring->timeout = $config->timeout;
            $recurring->serviceUrl = $config->serviceUrl . $config->getPayPlanEndpoint();

            static::$instance = new static($gateway, $recurring);
        }
    }

    /**
     * Gets the configured gateway connector
     *
     * @return IPaymentGateway
     */
    public function getClient()
    {
        return $this->gateway;
    }

    /**
     * Gets the configured recurring gateway connector
     *
     * @return IRecurringService
     */
    public function getRecurringClient()
    {
        return $this->recurring;
    }

    /**
     * @return ISecure3dProvider
     */
    public function getSecure3d($version)
    {
        $provider = $this->getSecure3dProvider($version);
        if ($provider != null) {
            return $provider;
        }
        throw new ConfigurationException(sprintf("Secure 3d is not configured for version %s", $version));
    }
}
