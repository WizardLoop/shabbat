# ✡️🕯️ Shabbat Bot

**WizardLoop Shabbat Bot** is an automated Telegram bot that changes group permissions at the entrance and exit of Shabbat, helping admins manage group activity and maintain Shabbat observance.

[![MIT License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![Made by WizardLoop](https://img.shields.io/badge/Made%20by-WizardLoop-blue)](https://github.com/WizardLoop)
[![Telegram Contact](https://img.shields.io/badge/contact-%40WizardLoop-blue?logo=telegram)](https://t.me/WizardLoop)

---

> ✨ Let your group rest on Shabbat! Automate muting/unmuting group chats every week, hands free.

---

## 🚀 Features

- 🕯 **Automatic Shabbat Mode:** Restricts group permissions at Shabbat entrance, restores at exit
- 🔔 **Customizable Messages:** Set your own "Shabbat" and "Motzei Shabbat" messages
- 👑 **Admin Panel:** Only admins can change settings or override
- 📦 **Zero dependencies:** Just MadelineProto & EnvLoader

---

## 📦 Installation

```bash
git clone https://github.com/WizardLoop/shabbat.git
cd shabbat
composer install
cp .env.example .env
# Fill your API credentials and settings in .env
php bot.php
