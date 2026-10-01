(function () {
  var registry = window.wc && window.wc.wcBlocksRegistry;
  var settingsApi = window.wc && window.wc.wcSettings;
  var el = window.wp && window.wp.element && window.wp.element.createElement;
  var decode = window.wp && window.wp.htmlEntities && window.wp.htmlEntities.decodeEntities;

  if (!registry || !settingsApi || !el || !decode) {
    return;
  }

  var settings = settingsApi.getSetting('uddoktagetway_data', {});
  var label = decode(settings.title || 'UddoktaGetway');

  var Content = function () {
    return el('div', null, decode(settings.description || ''));
  };

  registry.registerPaymentMethod({
    name: 'uddoktagetway',
    label: el('span', null, label),
    content: el(Content, null),
    edit: el(Content, null),
    canMakePayment: function () {
      return true;
    },
    ariaLabel: label,
    supports: {
      features: settings.supports || ['products']
    }
  });
})();
