<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace Spryker\Zed\Checkout\Dependency\Plugin\PlaceOrder;

use Generated\Shared\Transfer\QuoteTransfer;

interface CheckoutPreSaveHookInterface
{
    /**
     * Specification:
     * - Do something before orderTransfer save
     *
     * @api
     *
     * @param \Generated\Shared\Transfer\QuoteTransfer $quoteTransfer
     *
     * @return \Generated\Shared\Transfer\QuoteTransfer
     */
    public function preSave(QuoteTransfer $quoteTransfer);
}