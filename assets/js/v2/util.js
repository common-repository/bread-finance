const BreadUtil = {
    createBopisPayload: function(opts) {
        let fulfillmentType = this.parseFulfillmentType(opts);

        if (fulfillmentType === 'PICKUP') {
            return {
                pickupInformation: {
                    address: {
                        address1: opts.shippingContact.address,
                        address2: opts.shippingContact.address2,
                        locality: opts.shippingContact.city,
                        postalCode: opts.shippingContact.zip,
                        region: opts.shippingContact.state,
                        country: opts.shippingContact.country,
                    },
                },
                fulfillmentType: fulfillmentType
            };
        } else {
            return { fulfillmentType: fulfillmentType };
        }
    },
    parseFulfillmentType: function(opts) {
        let result = 'DELIVERY';
        let pickupLocationIds = ['local_pickup', 'pickup_location'];
        if (opts.shippingOptions && pickupLocationIds.includes(opts.shippingOptions[0].typeId)) {
            result = 'PICKUP';
        }
        return result;
    },
};

window.BreadUtil = BreadUtil;