<?php

namespace nineteenninetyfour\reviews\services;

use Craft;
use craft\base\Component;
use craft\helpers\Html;
use craft\web\Request;
use InvalidArgumentException;
use NineteenNinetyFour\Reviews\Core\Captcha\Captcha;
use NineteenNinetyFour\Reviews\Core\Captcha\Captchas;
use NineteenNinetyFour\Reviews\Core\Spam\Links;
use NineteenNinetyFour\Reviews\Core\Spam\TimeToken;
use nineteenninetyfour\reviews\http\CraftClient;
use nineteenninetyfour\reviews\Plugin;

/**
 * Keeping bots out of the review form, without bothering people:
 *
 * - a honeypot, a field people can't see and bots fill in;
 * - a time trap: forms sent within seconds of the page loading are a
 *   bot's. The form carries the time it was drawn, signed. A page served
 *   from a static cache was drawn long ago, so the form's small script
 *   also notes how long the page was open in the browser;
 * - too many links;
 * - a captcha, when one is set up: Cloudflare Turnstile, hCaptcha, Google
 *   reCAPTCHA (v2 or v3), or your own.
 */
class Spam extends Component
{
    public const HONEYPOT = 'website';

    public const TIME_FIELD = '_reviews_t';

    public const OPENED_FIELD = '_reviews_opened';

    private Captcha|false|null $captcha = null;

    /**
     * The hidden fields every review form carries.
     */
    public function hiddenFields(): string
    {
        return Html::tag('div', Html::tag('label', Html::encode(Craft::t('reviews', 'Leave this empty')).' '.Html::textInput(self::HONEYPOT, '', ['tabindex' => '-1', 'autocomplete' => 'off'])), [
            'style' => 'position:absolute!important;left:-10000px!important;width:1px;height:1px;overflow:hidden',
            'aria-hidden' => 'true',
        ])
            .Html::hiddenInput(self::TIME_FIELD, $this->token()->issue())
            .Html::hiddenInput(self::OPENED_FIELD, '', ['data-reviews-opened' => true]);
    }

    /**
     * Notes how long the page was open, for the time trap on cached pages.
     */
    public function script(): string
    {
        return '<script>(function(){var t=Date.now();document.querySelectorAll("[data-reviews-opened]").forEach(function(i){var f=i.form;if(f&&!f.dataset.reviewsTimed){f.dataset.reviewsTimed=1;f.addEventListener("submit",function(){f.querySelectorAll("[data-reviews-opened]").forEach(function(x){x.value=String(Math.round((Date.now()-t)/1000))})})}})})();</script>';
    }

    public function isBot(Request $request): bool
    {
        $honeypot = $request->getBodyParam(self::HONEYPOT);

        if ($honeypot !== null && $honeypot !== '') {
            return true;
        }

        return $this->token()->tooQuick(
            $request->getBodyParam(self::TIME_FIELD),
            $request->getBodyParam(self::OPENED_FIELD),
            max(0, Plugin::getInstance()->getSettings()->minimumSeconds),
        );
    }

    public function tooManyLinks(?string ...$texts): bool
    {
        return Links::count(...$texts) > max(0, Plugin::getInstance()->getSettings()->maxLinks);
    }

    /**
     * The captcha the form uses, or null for none.
     */
    public function captcha(): ?Captcha
    {
        if ($this->captcha !== null) {
            return $this->captcha ?: null;
        }

        $settings = Plugin::getInstance()->getSettings();
        $driver = trim($settings->captcha);
        $captcha = null;

        if ($driver !== '' && Captchas::builtIn($driver)) {
            $captcha = Captchas::make($driver, $settings->captchaSite(), $settings->captchaSecret(), new CraftClient(10), ['score' => $settings->captchaScore]);
        } elseif ($driver !== '') {
            if (! class_exists($driver) || ! is_subclass_of($driver, Captcha::class)) {
                throw new InvalidArgumentException("Unknown reviews captcha [{$driver}]. Use turnstile, hcaptcha, recaptcha, recaptcha_v3, or a class implementing ".Captcha::class.'.');
            }

            $captcha = Craft::createObject($driver);
        }

        $this->captcha = $captcha ?? false;

        return $captcha;
    }

    public function captchaPassed(Request $request): bool
    {
        $captcha = $this->captcha();

        if (! $captcha) {
            return true;
        }

        $token = $request->getBodyParam($captcha->field());

        return $captcha->verify(is_string($token) ? $token : null, $request->getUserIP());
    }

    protected function token(): TimeToken
    {
        return new TimeToken((string) Craft::$app->getConfig()->getGeneral()->securityKey);
    }
}
