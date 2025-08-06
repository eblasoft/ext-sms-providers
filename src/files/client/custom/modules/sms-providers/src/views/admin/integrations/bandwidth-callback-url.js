define('sms-providers:views/admin/integrations/bandwidth-callback-url', 'views/fields/url', function (Dep) {

    return Dep.extend({
        copyToClipboard: true,

        setup: function () {
            Dep.prototype.setup.call(this);

            const redirectUri = this.getConfig().get('siteUrl') + '/api/v1/SmsCallback/bandwidth';
            this.model.set(this.name, redirectUri);
        },
    });
});
