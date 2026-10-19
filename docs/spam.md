# Spam

Every review form has checks people never see:

- **A hidden field.** People can't see it; bots fill it in.
- **A time check.** People take more than a few seconds to write a review (**Fewest seconds to write a review**: 3). The form carries the time it was drawn, signed so it can't be made up. On a page cached whole, the form was drawn long ago, so a small script also sends how long the page was open.
- **Links.** Reviews with more than two links are refused (**Most links in a review**).
- **Rate limits.** Five reviews per visitor in ten minutes, and thirty "helpful" votes a minute, counted by their IP address, hashed, in the cache for as long as the limit lasts.

A bot that trips the hidden field or the time check is thanked as a person would be, and nothing is kept.

## Captchas

For more, choose a captcha under **Settings → Spam**: Cloudflare Turnstile, hCaptcha, or Google reCAPTCHA (v2, or v3 with a score). Enter its site key and secret key, best as environment variables:

```
CAPTCHA_SITE_KEY="…"
CAPTCHA_SECRET="…"
```

and `$CAPTCHA_SITE_KEY` and `$CAPTCHA_SECRET` in the settings. The ready-made form and `craft.reviews.formFields()` add the captcha's widget and script. A captcha sends the visitor's IP address to its provider: name it in your privacy notice.

### Your own

Set **Captcha** in `config/reviews.php` to a class implementing `NineteenNinetyFour\Reviews\Core\Captcha\Captcha`:

```php
'captcha' => \modules\FriendlyCaptcha::class,
```

It has three methods: `widget()` (the HTML for the form), `field()` (the form field its answer comes in) and `verify(?string $token, ?string $ip): bool`.

## Content-Security-Policy

Reviews writes no inline scripts: the time trap, and reCAPTCHA v3's token, are in one file, `reviews.js`, published with Craft's other control panel resources, from your own site, so `script-src 'self'` allows them. A captcha also needs its provider's addresses allowed: `challenges.cloudflare.com` for Turnstile, `js.hcaptcha.com` and `hcaptcha.com` for hCaptcha, and `www.google.com` and `www.gstatic.com` for reCAPTCHA (each in `script-src` and `frame-src`). With Blitz installed, the "Helpful" buttons fetch their CSRF token with Craft's own inline script: allow it with a nonce or hash, as for Craft's other inline scripts.
