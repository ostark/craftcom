import Vue from 'vue'
import Vuex from 'vuex'

Vue.use(Vuex);

export default {

    getCraftIdData(userId, cb, cbError) {
        let body = { userId: userId };
        let options = { emulateJSON: true };

        Vue.http.post(window.craftActionUrl+'/craftcom/id/craft-id', body, options)
            .then(response => cb(response.body))
            .catch(response => cbError(response));
    },

    disconnectApp(appHandle, cb, cbError) {
        let options = { emulateJSON: true };

        let formData = new FormData();
        formData.append('appTypeHandle', appHandle);
        formData.append(csrfTokenName, csrfTokenValue);

        Vue.http.post(window.craftActionUrl+'/craftcom/id/apps/disconnect', formData, options)
            .then(response => cb(response.body))
            .catch(response => cbError(response));
    },

    saveUser(user, cb, cbError) {
        let formData = new FormData();

        for (let attribute in user) {
            switch (attribute) {
                case 'id':
                    formData.append('userId', user[attribute]);
                    break;
                case 'email':
                case 'firstName':
                case 'lastName':
                case 'password':
                case 'newPassword':
                case 'photo':
                    formData.append(attribute, user[attribute]);
                    break;
                default:
                    formData.append('fields['+attribute+']', user[attribute]);
            }
        }

        formData.append('action', 'users/save-user');
        formData.append(csrfTokenName, csrfTokenValue);

        let options = { emulateJSON: true };

        Vue.http.post(window.craftActionUrl+'/users/save-user', formData, options)
            .then(response => cb(response.body))
            .catch(response => cbError(response.body));
    },

    uploadUserPhoto(formData, cb, cbError) {
        formData.append('action', 'craftcom/id/account/upload-user-photo');
        formData.append(csrfTokenName, csrfTokenValue);

        let options = { emulateJSON: true };

        Vue.http.post(window.craftActionUrl+'/craftcom/id/account/upload-user-photo', formData, options)
            .then(response => cb(response.body))
            .catch(response => cbError(response));
    },

    deleteUserPhoto(formData, cb, cbError) {
        formData.append('action', 'craftcom/id/account/delete-user-photo');
        formData.append(csrfTokenName, csrfTokenValue);

        let options = { emulateJSON: true };

        Vue.http.post(window.craftActionUrl+'/craftcom/id/account/delete-user-photo', formData, options)
            .then(response => cb(response.body))
            .catch(response => cbError(response));
    },

    getStripeAccount(cb, cbError) {
        Vue.http.get(window.craftIdUrl+'/stripe/account')
            .then(response => cb(response.body))
            .catch(response => cbError(response));
    },

    getStripeCustomer(cb, cbError) {
        Vue.http.get(window.craftIdUrl+'/stripe/customer')
            .then(response => cb(response.body))
            .catch(response => cbError(response));
    },

    disconnectStripeAccount(cb, cbError) {
        let options = { emulateJSON: true };

        Vue.http.post(window.craftIdUrl+'/stripe/disconnect', options)
            .then(response => cb(response.body))
            .catch(response => cbError(response));
    },

    saveCard(token, cb, cbError) {
        let body = { token: token.id };
        let options = { emulateJSON: true };

        Vue.http.post(window.craftIdUrl+'/stripe/save-card', body, options)
            .then(response => cb(response.body))
            .catch(response => cbError(response));
    },

    removeCard(cb, cbError) {
        let body = {};
        let options = { emulateJSON: true };

        Vue.http.post(window.craftIdUrl+'/stripe/remove-card', body, options)
            .then(response => cb(response.body))
            .catch(response => cbError(response));
    },

    saveLicense(license, cb, cbError) {
        let body = {
            entryId: license.id,
            siteId: 1,
            sectionId: 2,
            enabled: 1,
            fields: {}
        };

        for (let attribute in license) {
            switch (attribute) {
                case 'entryId':
                    // ignore
                    break;
                case 'title':
                    body[attribute] = license[attribute];
                    break;
                default:
                    body['fields'][attribute] = license[attribute];
            }
        }

        body['action'] = 'entries/save-entry';
        body[csrfTokenName] = csrfTokenValue;

        let options = { emulateJSON: true };

        Vue.http.post(window.craftActionUrl+'/entries/save-entry', body, options)
            .then(response => cb(response.body))
            .catch(response => cbError(response));
    },

    savePlugin(formData, cb, cbError) {
        formData.append('action', 'craftcom/plugins/save');
        formData.append(csrfTokenName, csrfTokenValue);

        let options = { emulateJSON: true };

        Vue.http.post(window.craftActionUrl+'/craftcom/plugins/save', formData, options)
            .then(response => cb(response.body))
            .catch(response => cbError(response));
    },

    submitPlugin(pluginId, cb, cbError) {
        let options = { emulateJSON: true };

        let formData = new FormData();
        formData.append('pluginId', pluginId);
        formData.append(csrfTokenName, csrfTokenValue);

        Vue.http.post(window.craftActionUrl+'/craftcom/plugins/submit', formData, options)
            .then(response => cb(response.body))
            .catch(response => cbError(response));
    }

}