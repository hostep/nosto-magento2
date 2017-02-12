<?php
/**
 * Copyright (c) 2017, Nosto Solutions Ltd
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without modification,
 * are permitted provided that the following conditions are met:
 *
 * 1. Redistributions of source code must retain the above copyright notice,
 * this list of conditions and the following disclaimer.
 *
 * 2. Redistributions in binary form must reproduce the above copyright notice,
 * this list of conditions and the following disclaimer in the documentation
 * and/or other materials provided with the distribution.
 *
 * 3. Neither the name of the copyright holder nor the names of its contributors
 * may be used to endorse or promote products derived from this software without
 * specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND
 * ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
 * WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
 * DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR
 * ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES
 * (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
 * LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON
 * ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS
 * SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * @author Nosto Solutions Ltd <contact@nosto.com>
 * @copyright 2017 Nosto Solutions Ltd
 * @license http://opensource.org/licenses/BSD-3-Clause BSD 3-Clause
 *
 */

namespace Nosto\Tagging\Model\Meta\Oauth;

use Magento\Framework\Locale\ResolverInterface;
use Magento\Framework\Url;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use NostoAccount;
use NostoOAuth;
use Psr\Log\LoggerInterface;

class Builder
{
    private $localeResolver;
    private $urlBuilder;
    private $logger;

    /**
     * @param ResolverInterface $localeResolver
     * @param Url $urlBuilder
     * @param LoggerInterface $logger
     */
    public function __construct(
        ResolverInterface $localeResolver,
        Url $urlBuilder,
        LoggerInterface $logger
    ) {
        $this->localeResolver = $localeResolver;
        $this->urlBuilder = $urlBuilder;
        $this->logger = $logger;
    }

    /**
     * @param StoreManagerInterface|Store $store
     * @param NostoAccount $account
     * @return NostoOauth
     */
    public function build(StoreManagerInterface $store, NostoAccount $account = null)
    {
        $metaData = new NostoOAuth();

        try {
            $metaData->setScopes(\NostoApiToken::getApiTokenNames());
            $redirectUrl = $this->urlBuilder->getUrl(
                'nosto/oauth',
                [
                    '_nosid' => true,
                    '_scope_to_url' => true,
                    '_scope' => $store->getCode(),
                ]
            );
            $metaData->setClientId('magento');
            $metaData->setClientSecret('magento');
            $metaData->setRedirectUrl($redirectUrl);
            $lang = substr($this->localeResolver->getLocale(), 0, 2);
            $metaData->setLanguageIsoCode($lang);
            if ($account !== null) {
                $metaData->setAccount($account);
            }
        } catch (\NostoException $e) {
            $this->logger->error($e->__toString());
        }

        return $metaData;
    }
}
