<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\AstoundAffirm\Model\Messaging;

use Magento\Catalog\Api\Data\ProductInterface;

/**
 * Affirm promo ids for the storefront placements, resolved through Affirm's own helper.
 *
 * Implementations never throw; an empty string means "no promo id" (affirm.js then uses the account default).
 */
interface PromoIdInterface
{
    /**
     * Promo id for a product (product page, listings): Affirm's as-low-as chain for that product.
     */
    public function forProduct(ProductInterface $product): string;

    /**
     * Promo id for the current cart: Affirm's as-low-as chain for the session quote.
     */
    public function forCart(): string;

    /**
     * Affirm's cart-size rule prepared for live switching on the cart page.
     */
    public function cartRule(): CartPromoRule;

    /**
     * Promo id for a banner. Home and category banners use only dated or default promo ids, never visitor or
     * quote data, because those pages are full-page cached.
     *
     * @param string $section 'product', 'cart' (or 'checkout_cart'); anything else is treated as home/category
     */
    public function forBanner(string $section, ?ProductInterface $product = null): string;
}
