<?php
// Update these static contact values to your direct support channels.
$supportEmail = 'crisssarbah@gmail.com';
$supportPhone = '+233205308494';
$supportTikTok = '@chito_gh';
$supportTikTokUrl = 'https://www.tiktok.com/@chito_gh';
?>
<style>
  .support-card {
    position: fixed;
    right: 1rem;
    bottom: 1rem;
    z-index: 70;
    width: auto;
    border-radius: 999px;
    border: 1px solid rgba(148, 163, 184, 0.26);
    background: linear-gradient(150deg, rgba(15, 23, 42, 0.9), rgba(30, 41, 59, 0.86));
    box-shadow: 0 10px 24px -18px rgba(15, 23, 42, 0.85);
    color: #e2e8f0;
    backdrop-filter: blur(6px);
  }

  .support-card__toggle {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    border: 0;
    background: transparent;
    color: inherit;
    cursor: pointer;
    padding: 0.48rem 0.72rem;
    font-size: 0.72rem;
    font-weight: 700;
    letter-spacing: 0.04em;
    text-transform: uppercase;
  }

  .support-card__toggle:focus-visible {
    outline: 2px solid #67e8f9;
    outline-offset: 2px;
  }

  .support-card__inner {
    display: none;
    width: min(88vw, 14rem);
    padding: 0.62rem 0.72rem 0.68rem;
    border-top: 1px solid rgba(148, 163, 184, 0.22);
    border-radius: 0 0 0.75rem 0.75rem;
  }

  .support-card.is-open {
    border-radius: 0.75rem;
  }

  .support-card.is-open .support-card__inner {
    display: block;
  }

  .support-card__title {
    margin: 0;
    font-size: 0.68rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: #bae6fd;
  }

  .support-card__builder {
    margin: 0.12rem 0 0;
    font-size: 0.64rem;
    font-weight: 600;
    letter-spacing: 0.03em;
    color: #93c5fd;
  }

  .support-card__list {
    margin: 0.4rem 0 0;
    padding: 0;
    list-style: none;
    display: grid;
    gap: 0.28rem;
  }

  .support-card__link {
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
    color: #e2e8f0;
    text-decoration: none;
    font-size: 0.74rem;
    font-weight: 600;
  }

  .support-card__link:hover {
    color: #67e8f9;
  }

  .support-card__icon {
    width: 0.82rem;
    height: 0.82rem;
    flex-shrink: 0;
    color: #7dd3fc;
  }

  @media (max-width: 640px) {
    .support-card {
      right: 0.75rem;
      bottom: 0.75rem;
    }

    .support-card__inner {
      width: min(90vw, 13.2rem);
    }
  }
</style>

<aside class="support-card" aria-label="Support contacts">
  <button type="button" class="support-card__toggle" id="supportCardToggle" aria-expanded="false" aria-controls="supportCardPanel">
    <svg class="support-card__icon" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M10 2a8 8 0 1 0 8 8 8 8 0 0 0-8-8zm.2 12.5h-1.4v-1.4h1.4zm1.4-5.1-.6.6a2 2 0 0 0-.8 1.5h-1.4a3.2 3.2 0 0 1 1.2-2.5l.8-.7a1.1 1.1 0 1 0-1.8-.8H7.6a2.5 2.5 0 1 1 4.9.8z"/></svg>
    <span>Need help?</span>
  </button>
  <div class="support-card__inner" id="supportCardPanel">
    <p class="support-card__title">Direct Support</p>
    <p class="support-card__builder">Khanun Inc.</p>
    <ul class="support-card__list">
      <li>
        <a class="support-card__link" href="mailto:<?= e($supportEmail) ?>">
          <svg class="support-card__icon" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M2 5a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v.4l-8 4.8-8-4.8V5zm0 2.7 7.5 4.5a1 1 0 0 0 1 0L18 7.7V15a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V7.7z"/></svg>
          <span><?= e($supportEmail) ?></span>
        </a>
      </li>
      <li>
        <a class="support-card__link" href="tel:<?= e(str_replace([' ', '-', '(', ')'], '', $supportPhone)) ?>">
          <svg class="support-card__icon" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M6.6 2.5a1 1 0 0 1 1 .74l.8 3a1 1 0 0 1-.27.98l-1.3 1.3a12 12 0 0 0 4.56 4.56l1.3-1.3a1 1 0 0 1 .98-.27l3 .8a1 1 0 0 1 .74 1v2.1a1 1 0 0 1-.9 1A14.5 14.5 0 0 1 2.5 3.4a1 1 0 0 1 1-.9h2.1z"/></svg>
          <span><?= e($supportPhone) ?></span>
        </a>
      </li>
      <li>
        <a class="support-card__link" href="<?= e($supportTikTokUrl) ?>" target="_blank" rel="noopener noreferrer">
          <svg class="support-card__icon" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M12.9 2.5c.28 1.1.92 1.98 1.86 2.62.8.55 1.67.84 2.62.88V8c-1.44-.04-2.78-.46-4.02-1.28v4.9c0 3-2.2 5.38-5.1 5.38a5.17 5.17 0 0 1-5.14-5.2 5.18 5.18 0 0 1 6.02-5.1v2.14a3.03 3.03 0 0 0-.88-.12 3.1 3.1 0 0 0-3.08 3.08c0 1.73 1.36 3.1 3.08 3.1 1.76 0 3-1.33 3-3.33V2.5h1.64z"/></svg>
          <span><?= e($supportTikTok) ?></span>
        </a>
      </li>
    </ul>
  </div>
</aside>

<script>
  (function () {
    const supportCard = document.querySelector('.support-card');
    const toggle = document.getElementById('supportCardToggle');

    if (!supportCard || !toggle) {
      return;
    }

    toggle.addEventListener('click', function () {
      const isOpen = supportCard.classList.toggle('is-open');
      toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    });
  })();
</script>
