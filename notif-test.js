const puppeteer = require('puppeteer-core');

(async () => {
  const browser = await puppeteer.launch({
    executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe',
    headless: 'new'
  });
  const page = await browser.newPage();
  await page.setViewport({ width: 1440, height: 900 });

  await page.goto('http://127.0.0.1:8899/login', { waitUntil: 'networkidle0' });
  await page.type('input[name="email"]', 'admin@daway.com');
  await page.type('input[name="password"]', 'Admin@12345');
  await page.click('button[type="submit"]');
  await page.waitForNavigation({ waitUntil: 'networkidle0' });

  const errors = [];
  page.on('pageerror', e => errors.push('PAGEERROR: ' + e.message));
  page.on('console', m => { if (m.type() === 'error') errors.push('CONSOLE: ' + m.text()); });

  await page.waitForSelector('#notificationBtn', { timeout: 10000 });
  await page.click('#notificationBtn');
  await new Promise(r => setTimeout(r, 1500));

  const html = await page.evaluate(() => {
    const d = document.getElementById('notificationsDropdown');
    const l = document.getElementById('notificationsList');
    return {
      dropdownClass: d.className,
      dropdownText: d.innerText,
      headerExists: !!d.querySelector('.notifications-header'),
      footerExists: !!d.querySelector('.notifications-footer'),
      itemsCount: l.querySelectorAll('.notification-item').length,
      listChildren: Array.from(l.children).map(c => c.className || c.tagName)
    };
  });

  console.log(JSON.stringify(html, null, 2));
  await page.screenshot({ path: 'C:/Users/MSI/AppData/Local/Temp/opencode/nb.png' });
  console.log('ERRORS:', JSON.stringify(errors, null, 2));
  await browser.close();
})();
