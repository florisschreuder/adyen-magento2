<?php
/**
 *
 * Adyen Payment module (https://www.adyen.com/)
 *
 * Copyright (c) 2015 Adyen BV (https://www.adyen.com/)
 * See LICENSE.txt for license details.
 *
 * Author: Adyen <magento@adyen.com>
 */

namespace Adyen\Payment\Model\Billing;

use Adyen\Payment\Helper\Config;
use Adyen\Payment\Helper\Data;
use Adyen\Payment\Helper\PaymentMethods;
use Adyen\Payment\Helper\Recurring;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;
use Magento\Framework\Stdlib\DateTime\DateTimeFactory;
use Magento\Paypal\Model\ResourceModel\Billing\Agreement\CollectionFactory;
use Magento\Sales\Model\Order\Payment;

class Agreement extends \Magento\Paypal\Model\Billing\Agreement
{
    /**
     * @var Data
     */
    private $adyenHelper;

    /** @var Config */
    private $configHelper;

    /** @var Recurring */
    private $recurringHelper;

    /**
     * Agreement constructor.
     *
     * @param Data $adyenHelper
     * @param Context $context
     * @param Registry $registry
     * @param \Magento\Payment\Helper\Data $paymentData
     * @param CollectionFactory $billingAgreementFactory
     * @param DateTimeFactory $dateFactory
     * @param AbstractResource|null $resource
     * @param AbstractDb|null $resourceCollection
     * @param array $data
     */
    public function __construct(
        Data $adyenHelper,
        Context $context,
        Registry $registry,
        \Magento\Payment\Helper\Data $paymentData,
        CollectionFactory $billingAgreementFactory,
        DateTimeFactory $dateFactory,
        Config $configHelper,
        Recurring $recurringHelper,
        AbstractResource $resource = null,
        AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct(
            $context,
            $registry,
            $paymentData,
            $billingAgreementFactory,
            $dateFactory,
            $resource,
            $resourceCollection,
            $data
        );

        $this->adyenHelper = $adyenHelper;
        $this->configHelper = $configHelper;
        $this->recurringHelper = $recurringHelper;
    }

    /**
     * Not yet possible to set different reference on customer level like magento 1.x version
     *
     * @return int
     */
    public function getCustomerReference()
    {
        return $this->getCustomerId();
    }

    /**
     * for async store of billing agreement through the recurring_contract notification
     *
     * @param $data
     * @return $this
     */
    public function parseRecurringContractData($data)
    {
        $this
            ->setMethodCode('adyen_oneclick')
            ->setReferenceId($data['recurringDetailReference'])
            ->setCreatedAt($data['creationDate']);

        $creationDate = str_replace(' ', '-', $data['creationDate']);
        $this->setCreatedAt($creationDate);

        //Billing agreement SEPA
        if (isset($data['bank']['iban'])) {
            $this->setAgreementLabel(
                __(
                    '%1, %2',
                    $data['bank']['iban'],
                    $data['bank']['ownerName']
                )
            );
        }

        // Billing agreement is CC
        if (isset($data['card']['number'])) {
            $ccType = $data['variant'];
            $ccTypes = $this->adyenHelper->getCcTypesAltData();

            if (isset($ccTypes[$ccType])) {
                $ccType = $ccTypes[$ccType]['name'];
            }

            $label = __(
                '%1, %2, **** %3',
                $ccType,
                $data['card']['holderName'],
                $data['card']['number'],
                $data['card']['expiryMonth'],
                $data['card']['expiryYear']
            );
            $this->setAgreementLabel($label);
        }

        if ($data['variant'] == 'paypal') {
            $email = '';

            if (isset($data['tokenDetails']['tokenData']['EmailId'])) {
                $email = $data['tokenDetails']['tokenData']['EmailId'];
            } elseif (isset($data['lastKnownShopperEmail'])) {
                $email = $data['lastKnownShopperEmail'];
            }

            $label = __(
                'PayPal %1',
                $email
            );
            $this->setAgreementLabel($label);
        }

        $this->setAgreementData($data);

        return $this;
    }

    /**
     * @param $data
     * @return $this
     */
    public function setAgreementData($data)
    {
        if (is_array($data)) {
            unset($data['creationDate'], $data['recurringDetailReference'], $data['payment_method']);
        }

        $this->setData('agreement_data', json_encode($data));
        return $this;
    }

    /**
     * @return mixed
     */
    public function getAgreementData()
    {
        return json_decode($this->getData('agreement_data'), true);
    }

    /**
     * For sync result to store billing agreement
     *
     * @param $contractDetail
     * @return $this
     */
    public function setCcBillingAgreement($contractDetail, $storeOneClick, $storeId)
    {
        $this
            ->setMethodCode('adyen_oneclick')
            ->setReferenceId($contractDetail['recurring.recurringDetailReference']);

        if (!isset($contractDetail['cardBin']) ||
            !isset($contractDetail['cardHolderName']) ||
            !isset($contractDetail['cardSummary']) ||
            !isset($contractDetail['expiryDate']) ||
            !isset($contractDetail['paymentMethod'])
        ) {
            $this->_errors[] = __(
                '"In the Additional data in API response section, select: Card bin,
                Card summary, Expiry Date, Cardholder name, Recurring details and Variant
                to create billing agreements immediately after the payment is authorized."'
            );
            return $this;
        }
        // Billing agreement is CC
        $ccType = $variant = $contractDetail['paymentMethod'];
        $ccTypes = $this->adyenHelper->getCcTypesAltData();

        if (isset($ccTypes[$ccType])) {
            $ccType = $ccTypes[$ccType]['name'];
        }

        if ($contractDetail['cardHolderName']) {
            $label = __(
                '%1, %2, **** %3',
                $ccType,
                $contractDetail['cardHolderName'],
                $contractDetail['cardSummary']
            );
        } else {
            $label = __(
                '%1, **** %2',
                $ccType,
                $contractDetail['cardSummary']
            );
        }

        $this->setAgreementLabel($label);

        $expiryDate = explode('/', $contractDetail['expiryDate']);

        if (!empty($contractDetail['pos_payment'])) {
            $recurringType = $this->adyenHelper->getAdyenPosCloudConfigData('recurring_type', $storeId);
        } else {
            $recurringType = $this->recurringHelper->getRecurringTypeFromSetting($storeId);
        }

        $agreementData = [
            'card' => [
                'holderName' => $contractDetail['cardHolderName'],
                'number' => $contractDetail['cardSummary'],
                'expiryMonth' => $expiryDate[0],
                'expiryYear' => $expiryDate[1]
            ],
            'variant' => $variant,
            // contractTypes should be changed from an array to a single value in the future. It has not been done yet
            // to ensure past tokens are still operational.
            'contractTypes' => explode(',', $recurringType)
        ];

        if (!empty($contractDetail['pos_payment'])) {
            $agreementData['posPayment'] = true;
        }

        $this->setAgreementData($agreementData);

        return $this;
    }

    /**
     * @param Payment $payment
     * @param $recurringDetailReference
     * @return $this
     * @throws \Magento\Framework\Exception\LocalizedException
     * todo: refactor or remove this method as those fields are set later
     */
    public function importOrderPaymentWithRecurringDetailReference(Payment $payment, $recurringDetailReference)
    {
        $baData = $payment->getBillingAgreementData();
        $this->_paymentMethodInstance = (isset($baData['method_code']))
            ? $this->_paymentData->getMethodInstance($baData['method_code'])
            : $payment->getMethodInstance();
        if(empty($baData['billing_agreement_id'])){
            $baData['billing_agreement_id'] = $recurringDetailReference;
        }

        $this->_paymentMethodInstance->setStore($payment->getMethodInstance()->getStore());
        $this->setCustomerId($payment->getOrder()->getCustomerId())
            ->setMethodCode($this->_paymentMethodInstance->getCode())
            ->setReferenceId($baData['billing_agreement_id'])
            ->setStatus(self::STATUS_ACTIVE)
            ->setAgreementLabel($this->_paymentMethodInstance->getTitle());

        return $this;
    }

    public function getErrors()
    {
        return $this->_errors;
    }
}
