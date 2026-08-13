const puppeteer = require('puppeteer-core');

(async () => {
  const browser = await puppeteer.launch({
    executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe',
    headless: 'new'
  });
  const page = await browser.newPage();
  await page.setViewport({ width: 1440, height: 900 });

  await page.goto('http://127.0.0.1:8899/login', { waitUntil: 'networkidle0' });
  await page.type('#identityInput', 'admin@daway.com');
  await page.type('#passwordInput', 'Admin@12345');
  await page.click('#submitBtn');
  await page.waitForSelector('.sidebar-pro', { timeout: 40000 });
  await page.goto('http://127.0.0.1:8899/dashboard', { waitUntil: 'networkidle0' });

  const probe = async () => {
    return page.evaluate(() => {
      const btn = document.querySelector('.user-profile-footer .more-options-btn').getBoundingClientRect();
      const avatar = document.querySelector('.user-profile-footer .avatar-box').getBoundingClientRect();
      const sb = document.querySelector('.sidebar-pro').getBoundingClientRect();
      return {
        sidebar: { left: Math.round(sb.left), right: Math.round(sb.right) },
        logout: { left: Math.round(btn.left), right: Math.round(btn.right) },
        avatar: { left: Math.round(avatar.left), right: Math.round(avatar.right) }
      };
    });
  };

  console.log('AR (rtl):', JSON.stringify(await probe()));
  await page.evaluate(() => document.documentElement.setAttribute('dir', 'ltr'));
  console.log('EN (ltr):', JSON.stringify(await probe()));
  await browser.close();
})();