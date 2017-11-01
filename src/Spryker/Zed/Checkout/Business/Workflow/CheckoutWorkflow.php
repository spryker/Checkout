<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace Spryker\Zed\Checkout\Business\Workflow;

use Generated\Shared\Transfer\CheckoutResponseTransfer;
use Generated\Shared\Transfer\QuoteTransfer;
use Generated\Shared\Transfer\SaveOrderTransfer;
use Propel\Runtime\Propel;

class CheckoutWorkflow implements CheckoutWorkflowInterface
{
    /**
     * @var \Spryker\Zed\Checkout\Dependency\Plugin\CheckoutPreConditionInterface[]
     */
    protected $preConditionStack;

    /**
     * @var \Spryker\Zed\Checkout\Dependency\Plugin\CheckoutSaveOrderInterface[]
     */
    protected $saveOrderStack;

    /**
     * @var \Spryker\Zed\Checkout\Dependency\Plugin\CheckoutPostSaveHookInterface[]
     */
    protected $postSaveHookStack;

    /**
     * @var \Spryker\Zed\Checkout\Dependency\Plugin\CheckoutPreSaveHookInterface[]
     */
    protected $preSaveStack;

    /**
     * @param \Spryker\Zed\Checkout\Dependency\Plugin\CheckoutPreConditionInterface[] $preConditionStack
     * @param \Spryker\Zed\Checkout\Dependency\Plugin\CheckoutSaveOrderInterface[] $saveOrderStack
     * @param \Spryker\Zed\Checkout\Dependency\Plugin\CheckoutPostSaveHookInterface[] $postSaveHookStack
     * @param \Spryker\Zed\Checkout\Dependency\Plugin\CheckoutPreSaveHookInterface[] $preSave
     */
    public function __construct(
        array $preConditionStack,
        array $saveOrderStack,
        array $postSaveHookStack,
        array $preSave = []
    ) {
        $this->preConditionStack = $preConditionStack;
        $this->postSaveHookStack = $postSaveHookStack;
        $this->saveOrderStack = $saveOrderStack;
        $this->preSaveStack = $preSave;
    }

    /**
     * @param \Generated\Shared\Transfer\QuoteTransfer $quoteTransfer
     *
     * @return \Generated\Shared\Transfer\CheckoutResponseTransfer
     */
    public function placeOrder(QuoteTransfer $quoteTransfer)
    {
        $checkoutResponse = $this->createCheckoutResponseTransfer();
        $checkoutResponse->setIsSuccess(false);

        $isPassed = $this->checkPreConditions($quoteTransfer, $checkoutResponse);

        if (!$isPassed) {
            return $checkoutResponse;
        }

        $quoteTransfer = $this->doPreSave($quoteTransfer);

        $orderTransfer = $this->doSaveOrder($quoteTransfer, $checkoutResponse);
        if (!$this->hasErrors($checkoutResponse)) {
            $this->executePostHooks($orderTransfer, $checkoutResponse);

            $isSuccess = !$this->hasErrors($checkoutResponse);
            $checkoutResponse->setIsSuccess($isSuccess);
        }

        return $checkoutResponse;
    }

    /**
     * @param \Generated\Shared\Transfer\QuoteTransfer $quoteTransfer
     * @param \Generated\Shared\Transfer\CheckoutResponseTransfer $checkoutResponse
     *
     * @return bool
     */
    protected function checkPreConditions(QuoteTransfer $quoteTransfer, CheckoutResponseTransfer $checkoutResponse)
    {
        $isPassed = true;

        foreach ($this->preConditionStack as $preCondition) {
            $isPassed &= $preCondition->checkCondition($quoteTransfer, $checkoutResponse);
        }

        return (bool)$isPassed;
    }

    /**
     * @param \Generated\Shared\Transfer\CheckoutResponseTransfer $checkoutResponse
     *
     * @return bool
     */
    protected function hasErrors(CheckoutResponseTransfer $checkoutResponse)
    {
        return count($checkoutResponse->getErrors()) > 0;
    }

    /**
     * @param \Generated\Shared\Transfer\QuoteTransfer $quoteTransfer
     * @param \Generated\Shared\Transfer\CheckoutResponseTransfer $checkoutResponse
     *
     * @return \Generated\Shared\Transfer\QuoteTransfer
     */
    protected function doSaveOrder(QuoteTransfer $quoteTransfer, CheckoutResponseTransfer $checkoutResponse)
    {
        Propel::getConnection()->beginTransaction();

        foreach ($this->saveOrderStack as $orderSaver) {
            $orderSaver->saveOrder($quoteTransfer, $checkoutResponse);
        }

        if ($this->hasErrors($checkoutResponse)) {
            Propel::getConnection()->rollBack();
            return $quoteTransfer;
        }

        Propel::getConnection()->commit();

        return $quoteTransfer;
    }

    /**
     * @param \Generated\Shared\Transfer\QuoteTransfer $quoteTransfer
     * @param \Generated\Shared\Transfer\CheckoutResponseTransfer $checkoutResponse
     *
     * @return void
     */
    protected function executePostHooks(QuoteTransfer $quoteTransfer, $checkoutResponse)
    {
        foreach ($this->postSaveHookStack as $postSaveHook) {
            $postSaveHook->executeHook($quoteTransfer, $checkoutResponse);
        }
    }

    /**
     * @return \Generated\Shared\Transfer\CheckoutResponseTransfer
     */
    protected function createCheckoutResponseTransfer()
    {
        $checkoutResponseTransfer = new CheckoutResponseTransfer();
        $checkoutResponseTransfer->setSaveOrder(new SaveOrderTransfer());

        return $checkoutResponseTransfer;
    }

    /**
     * @param \Generated\Shared\Transfer\QuoteTransfer $quoteTransfer
     *
     * @return \Generated\Shared\Transfer\QuoteTransfer
     */
    protected function doPreSave(QuoteTransfer $quoteTransfer)
    {
        foreach ($this->preSaveStack as $preSavePlugin) {
            $quoteTransfer = $preSavePlugin->preSave($quoteTransfer);
        }

        return $quoteTransfer;
    }
}
