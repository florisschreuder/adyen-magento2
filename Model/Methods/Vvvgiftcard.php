<?php

/**
 * Adyen Payment Module
 *
 * Copyright (c) 2023 Adyen N.V  .
 */

namespace Adyen\Payment\Model\Methods;

use Adyen\Payment\Model\AdyenPaymentMethod;
use Adyen\Payment\Model\Method\PaymentMethodInterface;

class Vvvgiftcard extends AdyenPaymentMethod implements PaymentMethodInterface
{
	public const CODE = 'adyen_vvvgiftcard';
	public const TX_VARIANT = 'vvvgiftcard';
	public const NAME = 'VVV Giftcard';

	public function supportsRecurring(): bool
	{
		return false;
	}


	public function supportsManualCapture(): bool
	{
		return false;
	}


	public function supportsAutoCapture(): bool
	{
		return false;
	}


	public function supportsCardOnFile(): bool
	{
		return false;
	}


	public function supportsSubscription(): bool
	{
		return false;
	}


	public function supportsUnscheduledCardOnFile(): bool
	{
		return false;
	}


	public function isWallet(): bool
	{
		return false;
	}
}
