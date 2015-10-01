<?php

/**
 * (c) Spryker Systems GmbH copyright protected
 */

namespace SprykerFeature\Zed\Checkout\Business\Workflow;

use Generated\Shared\Checkout\CheckoutRequestInterface;
use Generated\Shared\Checkout\CheckoutResponseInterface;
use Generated\Shared\Checkout\OrderInterface;
use Generated\Shared\Transfer\CheckoutErrorTransfer;
use Generated\Shared\Transfer\CheckoutRequestTransfer;
use Generated\Shared\Transfer\CheckoutResponseTransfer;
use Generated\Shared\Transfer\OrderTransfer;
use Propel\Runtime\Propel;
use SprykerFeature\Shared\Checkout\CheckoutConfig;
use SprykerFeature\Shared\Library\Error\ErrorHandler;
use SprykerFeature\Zed\Checkout\Dependency\Facade\CheckoutToOmsInterface;
use SprykerFeature\Zed\Checkout\Dependency\Plugin\CheckoutOrderHydrationInterface;
use SprykerFeature\Zed\Checkout\Dependency\Plugin\CheckoutPostSaveHookInterface;
use SprykerFeature\Zed\Checkout\Dependency\Plugin\CheckoutPreconditionInterface;
use SprykerFeature\Zed\Checkout\Dependency\Plugin\CheckoutPreHydrationInterface;
use SprykerFeature\Zed\Checkout\Dependency\Plugin\CheckoutSaveOrderInterface;

class CheckoutWorkflow implements CheckoutWorkflowInterface
{

    /**
     * @var CheckoutPreconditionInterface[]
     */
    protected $preConditionStack;

    /**
     * @var CheckoutPreHydrationInterface[]
     */
    protected $preHydrationStack;

    /**
     * @var CheckoutOrderHydrationInterface[]
     */
    protected $orderHydrationStack;

    /**
     * @var CheckoutSaveOrderInterface[]
     */
    protected $saveOrderStack;

    /**
     * @var CheckoutPostSaveHookInterface[]
     */
    protected $postSaveHookStack;

    /**
     * @var CheckoutToOmsInterface
     */
    protected $omsFacade;

    /**
     * @param CheckoutPreconditionInterface[] $preConditionStack
     * @param CheckoutPreHydrationInterface[] $preHydrationStack
     * @param CheckoutOrderHydrationInterface[] $orderHydrationStack
     * @param CheckoutSaveOrderInterface[] $saveOrderStack
     * @param CheckoutPostSaveHookInterface[] $postSaveHookStack
     * @param CheckoutToOmsInterface $omsFacade
     */
    public function __construct(
        array $preConditionStack,
        array $preHydrationStack,
        array $orderHydrationStack,
        array $saveOrderStack,
        array $postSaveHookStack,
        CheckoutToOmsInterface $omsFacade
    ) {
        $this->preConditionStack = $preConditionStack;
        $this->preHydrationStack = $preHydrationStack;
        $this->postSaveHookStack = $postSaveHookStack;
        $this->orderHydrationStack = $orderHydrationStack;
        $this->saveOrderStack = $saveOrderStack;
        $this->omsFacade = $omsFacade;
    }

    /**
     * @param CheckoutRequestTransfer $checkoutRequest
     *
     * @return CheckoutResponseTransfer
     */
    public function requestCheckout(CheckoutRequestTransfer $checkoutRequest)
    {
        $checkoutResponse = new CheckoutResponseTransfer();
        $checkoutResponse->setIsSuccess(false);

        $this->checkPreConditions($checkoutRequest, $checkoutResponse);

        if (!$this->hasErrors($checkoutResponse)) {
            $this->preHydrate($checkoutRequest, $checkoutResponse);

            $orderTransfer = $this->getOrderTransfer();
            $this->hydrateOrder($orderTransfer, $checkoutRequest);

            $orderTransfer = $this->doSaveOrder($orderTransfer, $checkoutResponse);

            $checkoutResponse->setOrder($orderTransfer);

            if (!$this->hasErrors($checkoutResponse)) {
                $this->triggerStateMachine($orderTransfer);
                $this->executePostHooks($orderTransfer, $checkoutResponse);

                $checkoutResponse->setIsSuccess(true);
            }
        }

        return $checkoutResponse;
    }

    /**
     * @param CheckoutRequestInterface $checkoutRequest
     * @param CheckoutResponseInterface $checkoutResponse
     */
    protected function preHydrate(CheckoutRequestInterface $checkoutRequest, CheckoutResponseInterface $checkoutResponse)
    {
        foreach ($this->preHydrationStack as $preHydrator) {
            $preHydrator->execute($checkoutRequest, $checkoutResponse);
        }
    }

    /**
     * @param CheckoutRequestInterface $checkoutRequest
     * @param CheckoutResponseInterface $checkoutResponse
     */
    private function checkPreConditions(CheckoutRequestInterface $checkoutRequest, CheckoutResponseInterface $checkoutResponse)
    {
        try {
            foreach ($this->preConditionStack as $preCondition) {
                $preCondition->checkCondition($checkoutRequest, $checkoutResponse);
            }
        } catch (\Exception $e) {
            $error = $this->handleCheckoutError($e);

            $checkoutResponse
                ->addError($error)
                ->setIsSuccess(false)
            ;
        }
    }

    /**
     * @param CheckoutResponseInterface $checkoutResponse
     *
     * @return bool
     */
    private function hasErrors(CheckoutResponseInterface $checkoutResponse)
    {
        return count($checkoutResponse->getErrors()) > 0;
    }

    /**
     * @param OrderTransfer $orderTransfer
     * @param CheckoutResponseTransfer $checkoutResponse
     *
     * @return OrderInterface
     */
    protected function doSaveOrder(OrderTransfer $orderTransfer, CheckoutResponseTransfer $checkoutResponse)
    {
        Propel::getConnection()->beginTransaction();

        try {
            foreach ($this->saveOrderStack as $orderSaver) {
                $orderSaver->saveOrder($orderTransfer, $checkoutResponse);
            }

            if (!$this->hasErrors($checkoutResponse)) {
                Propel::getConnection()->commit();
            } else {
                Propel::getConnection()->rollBack();

                return $orderTransfer;
            }
        } catch (\Exception $e) {
            Propel::getConnection()->rollBack();

            $error = $this->handleCheckoutError($e);

            $checkoutResponse
                ->addError($error)
                ->setIsSuccess(false)
            ;
        }

        return $orderTransfer;
    }

    /**
     * @return OrderTransfer
     */
    protected function getOrderTransfer()
    {
        return new OrderTransfer();
    }

    /**
     * @param OrderInterface $orderTransfer
     */
    private function triggerStateMachine(OrderInterface $orderTransfer)
    {
        $itemIds = [];

        foreach ($orderTransfer->getItems() as $item) {
            $itemIds[] = $item->getIdSalesOrderItem();
        }

        $this->omsFacade->triggerEventForNewOrderItems($itemIds);
    }

    /**
     * @param OrderTransfer $orderTransfer
     * @param CheckoutRequestTransfer $checkoutRequest
     */
    private function hydrateOrder(OrderTransfer $orderTransfer, CheckoutRequestTransfer $checkoutRequest)
    {
        foreach ($this->orderHydrationStack as $orderHydrator) {
            $orderHydrator->hydrateOrder($orderTransfer, $checkoutRequest);
        }
    }

    /**
     * @param OrderTransfer $orderTransfer
     * @param CheckoutResponseTransfer $checkoutResponse
     */
    private function executePostHooks($orderTransfer, $checkoutResponse)
    {
        try {
            foreach ($this->postSaveHookStack as $postSaveHook) {
                $postSaveHook->executeHook($orderTransfer, $checkoutResponse);
            }
        } catch (\Exception $e) {
            $error = $this->handleCheckoutError($e);

            $checkoutResponse
                ->addError($error)
                ->setIsSuccess(false)
            ;
        }
    }

    /**
     * @param \Exception $exception
     * @return CheckoutErrorTransfer
     */
    protected function handleCheckoutError(\Exception $exception)
    {
        ErrorHandler::initialize()->handleException($exception, false, false);

        $error = new CheckoutErrorTransfer();

        $error
            ->setMessage($exception->getMessage())
            ->setErrorCode(CheckoutConfig::ERROR_CODE_UNKNOWN_ERROR)
            ->setType(get_class($exception))
            ->setTrace($exception->getTraceAsString())
        ;

        return $error;
    }

}
