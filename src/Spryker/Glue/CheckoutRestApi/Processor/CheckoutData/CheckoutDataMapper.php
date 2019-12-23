<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace Spryker\Glue\CheckoutRestApi\Processor\CheckoutData;

use Generated\Shared\Transfer\RestAddressTransfer;
use Generated\Shared\Transfer\RestCheckoutDataResponseAttributesTransfer;
use Generated\Shared\Transfer\RestCheckoutDataTransfer;
use Generated\Shared\Transfer\RestCheckoutRequestAttributesTransfer;
use Generated\Shared\Transfer\RestPaymentMethodTransfer;
use Generated\Shared\Transfer\RestPaymentProviderTransfer;
use Generated\Shared\Transfer\RestShipmentMethodTransfer;
use Generated\Shared\Transfer\ShipmentMethodTransfer;
use Spryker\Glue\CheckoutRestApi\CheckoutRestApiConfig;
use Spryker\Glue\CheckoutRestApi\Processor\Exception\PaymentMethodNotConfiguredException;

class CheckoutDataMapper implements CheckoutDataMapperInterface
{
    /**
     * @var \Spryker\Glue\CheckoutRestApiExtension\Dependency\Plugin\CheckoutDataResponseMapperPluginInterface[]
     */
    protected $checkoutDataResponseMapperPlugins;

    /**
     * @var \Spryker\Glue\CheckoutRestApi\CheckoutRestApiConfig
     */
    protected $config;

    /**
     * @param \Spryker\Glue\CheckoutRestApiExtension\Dependency\Plugin\CheckoutDataResponseMapperPluginInterface[] $checkoutDataResponseMapperPlugins
     * @param \Spryker\Glue\CheckoutRestApi\CheckoutRestApiConfig $config
     */
    public function __construct(
        array $checkoutDataResponseMapperPlugins,
        CheckoutRestApiConfig $config
    ) {
        $this->checkoutDataResponseMapperPlugins = $checkoutDataResponseMapperPlugins;
        $this->config = $config;
    }

    /**
     * @param \Generated\Shared\Transfer\RestCheckoutDataTransfer $restCheckoutDataTransfer
     * @param \Generated\Shared\Transfer\RestCheckoutRequestAttributesTransfer $restCheckoutRequestAttributesTransfer
     *
     * @return \Generated\Shared\Transfer\RestCheckoutDataResponseAttributesTransfer
     */
    public function mapRestCheckoutDataTransferToRestCheckoutDataResponseAttributesTransfer(
        RestCheckoutDataTransfer $restCheckoutDataTransfer,
        RestCheckoutRequestAttributesTransfer $restCheckoutRequestAttributesTransfer
    ): RestCheckoutDataResponseAttributesTransfer {
        $restCheckoutDataResponseAttributesTransfer = new RestCheckoutDataResponseAttributesTransfer();

        $restCheckoutDataResponseAttributesTransfer = $this->mapRestAddressTransfer(
            $restCheckoutDataTransfer,
            $restCheckoutDataResponseAttributesTransfer
        );
        if ($this->config->getAllowPaymentProvidersInAttributes()) {
            $restCheckoutDataResponseAttributesTransfer = $this->mapPaymentProviders(
                $restCheckoutDataTransfer,
                $restCheckoutDataResponseAttributesTransfer
            );
        }
        if ($this->config->getAllowShipmentMethodsInAttributes()) {
            $restCheckoutDataResponseAttributesTransfer = $this->mapShipmentMethods(
                $restCheckoutDataTransfer,
                $restCheckoutDataResponseAttributesTransfer
            );
        }

        foreach ($this->checkoutDataResponseMapperPlugins as $checkoutDataResponseMapperPlugin) {
            $restCheckoutDataResponseAttributesTransfer = $checkoutDataResponseMapperPlugin->mapRestCheckoutDataResponseTransferToRestCheckoutDataResponseAttributesTransfer(
                $restCheckoutDataTransfer,
                $restCheckoutRequestAttributesTransfer,
                $restCheckoutDataResponseAttributesTransfer
            );
        }

        return $restCheckoutDataResponseAttributesTransfer;
    }

    /**
     * @param \Generated\Shared\Transfer\RestCheckoutDataTransfer $restCheckoutDataTransfer
     * @param \Generated\Shared\Transfer\RestCheckoutDataResponseAttributesTransfer $restCheckoutDataResponseAttributesTransfer
     *
     * @return \Generated\Shared\Transfer\RestCheckoutDataResponseAttributesTransfer
     */
    protected function mapRestAddressTransfer(
        RestCheckoutDataTransfer $restCheckoutDataTransfer,
        RestCheckoutDataResponseAttributesTransfer $restCheckoutDataResponseAttributesTransfer
    ): RestCheckoutDataResponseAttributesTransfer {
        $addresses = $restCheckoutDataTransfer->getAddresses()->getAddresses();
        foreach ($addresses as $addressTransfer) {
            $restCheckoutDataResponseAttributesTransfer->addAddress(
                (new RestAddressTransfer())->fromArray(
                    $addressTransfer->toArray(),
                    true
                )->setId($addressTransfer->getUuid())
            );
        }

        return $restCheckoutDataResponseAttributesTransfer;
    }

    /**
     * @deprecated Will be removed in next major release.
     *
     * @param \Generated\Shared\Transfer\RestCheckoutDataTransfer $checkoutDataTransfer
     * @param \Generated\Shared\Transfer\RestCheckoutDataResponseAttributesTransfer $restCheckoutDataResponseAttributesTransfer
     *
     * @return \Generated\Shared\Transfer\RestCheckoutDataResponseAttributesTransfer
     */
    protected function mapPaymentProviders(
        RestCheckoutDataTransfer $checkoutDataTransfer,
        RestCheckoutDataResponseAttributesTransfer $restCheckoutDataResponseAttributesTransfer
    ): RestCheckoutDataResponseAttributesTransfer {
        foreach ($checkoutDataTransfer->getPaymentProviders()->getPaymentProviders() as $paymentProviderTransfer) {
            $restPaymentProviderTransfer = new RestPaymentProviderTransfer();
            $restPaymentProviderTransfer->setPaymentProviderName($paymentProviderTransfer->getPaymentProviderKey());

            foreach ($paymentProviderTransfer->getPaymentMethods() as $paymentMethodTransfer) {
                $paymentSelection = $this->findPaymentSelectionByPaymentProviderAndMethodNames($paymentProviderTransfer->getPaymentProviderKey(), $paymentMethodTransfer->getName());

                if (!$paymentSelection) {
                    continue;
                }

                $restPaymentMethodTransfer = (new RestPaymentMethodTransfer())
                    ->setPaymentMethodName($paymentMethodTransfer->getName())
                    ->setRequiredRequestData(
                        $this->config->getRequiredRequestDataForPaymentMethod($paymentMethodTransfer->getMethodName())
                    );

                $restPaymentProviderTransfer->addPaymentMethod($restPaymentMethodTransfer);
            }
            $restCheckoutDataResponseAttributesTransfer->addPaymentProvider($restPaymentProviderTransfer);
        }

        return $restCheckoutDataResponseAttributesTransfer;
    }

    /**
     * @param string $paymentProviderName
     * @param string $paymentMethodName
     *
     * @throws \Spryker\Glue\CheckoutRestApi\Processor\Exception\PaymentMethodNotConfiguredException
     *
     * @return string|null
     */
    protected function findPaymentSelectionByPaymentProviderAndMethodNames(string $paymentProviderName, string $paymentMethodName): ?string
    {
        if ($this->config->isPaymentProviderMethodToStateMachineMappingEnabled()) {
            $paymentProviderMethodToStateMachineMapping = $this->config->getPaymentProviderMethodToStateMachineMapping();

            if (!isset($paymentProviderMethodToStateMachineMapping[$paymentProviderName][$paymentMethodName])) {
                throw new PaymentMethodNotConfiguredException(sprintf(
                    'Payment method "%s" for payment provider "%s" is not configured in CheckoutRestApiConfig::getPaymentProviderMethodToStateMachineMapping()',
                    $paymentMethodName,
                    $paymentProviderName
                ));
            }

            return $paymentProviderMethodToStateMachineMapping[$paymentProviderName][$paymentMethodName];
        }

        return $paymentMethodName;
    }

    /**
     * @deprecated Will be removed in next major release.
     *
     * @param \Generated\Shared\Transfer\RestCheckoutDataTransfer $checkoutDataTransfer
     * @param \Generated\Shared\Transfer\RestCheckoutDataResponseAttributesTransfer $restCheckoutDataResponseAttributesTransfer
     *
     * @return \Generated\Shared\Transfer\RestCheckoutDataResponseAttributesTransfer
     */
    protected function mapShipmentMethods(
        RestCheckoutDataTransfer $checkoutDataTransfer,
        RestCheckoutDataResponseAttributesTransfer $restCheckoutDataResponseAttributesTransfer
    ): RestCheckoutDataResponseAttributesTransfer {
        $shipmentMethodTransfers = $checkoutDataTransfer->getShipmentMethods()->getMethods();
        foreach ($shipmentMethodTransfers as $shipmentMethodTransfer) {
            $restShipmentMethodTransfer = $this->mapShipmentMethodTransferToRestShipmentMethodTransfer(
                $shipmentMethodTransfer,
                new RestShipmentMethodTransfer()
            );

            $restCheckoutDataResponseAttributesTransfer->addShipmentMethod($restShipmentMethodTransfer);
        }

        return $restCheckoutDataResponseAttributesTransfer;
    }

    /**
     * @param \Generated\Shared\Transfer\ShipmentMethodTransfer $shipmentMethodTransfer
     * @param \Generated\Shared\Transfer\RestShipmentMethodTransfer $restShipmentMethodTransfer
     *
     * @return \Generated\Shared\Transfer\RestShipmentMethodTransfer
     */
    protected function mapShipmentMethodTransferToRestShipmentMethodTransfer(
        ShipmentMethodTransfer $shipmentMethodTransfer,
        RestShipmentMethodTransfer $restShipmentMethodTransfer
    ): RestShipmentMethodTransfer {
        $restShipmentMethodTransfer
            ->fromArray($shipmentMethodTransfer->toArray(), true)
            ->setPrice($shipmentMethodTransfer->getStoreCurrencyPrice())
            ->setId($shipmentMethodTransfer->getIdShipmentMethod());

        $moneyValueTransfer = $shipmentMethodTransfer->getPrices()->getIterator()->current();

        if (!$moneyValueTransfer) {
            return $restShipmentMethodTransfer;
        }

        $restShipmentMethodTransfer->setDefaultGrossPrice($moneyValueTransfer->getGrossAmount());
        $restShipmentMethodTransfer->setDefaultNetPrice($moneyValueTransfer->getNetAmount());

        return $restShipmentMethodTransfer;
    }
}
