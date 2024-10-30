const plugin_settings = window.mw_localized_data;
const settings = window.wc.wcSettings.getSetting(`${plugin_settings.gateway_token}_data`, {});
const label = window.wp.htmlEntities.decodeEntities(settings.title);
const element = window.wp.element
const FORM_ELEMENT = 'wc-block-components-form';

const parse = (str) => {
    return Function(`'use strict'; return (${str})`)()
}
const bread_sdk = parse(settings.tenant_sdk);

const fetchBreadOptions = async(billing, shipping) => {
    let breadOpts = null;
    let billing_address = billing.billingAddress;
    let shipping_address = shipping.shippingAddress;

    let data = {
        'action': 'bread_get_options',
        'page_type': 'checkout',
        'billing_first_name': billing_address.first_name,
        'billing_last_name': billing_address.last_name,
        'billing_company': billing_address.company,
        'billing_country': billing_address.country,
        'billing_address_1': billing_address.address_1,
        'billing_address_2': billing_address.address_2,
        'billing_city': billing_address.city,
        'billing_state': billing_address.state,
        'billing_postcode': billing_address.postcode,
        'billing_phone': billing_address.phone,
        'billing_email': billing_address.email,

        'shipping_first_name': shipping_address.first_name,
        'shipping_last_name': shipping_address.last_name,
        'shipping_company': shipping_address.company,
        'shipping_country': shipping_address.country,
        'shipping_address_1': shipping_address.address_1,
        'shipping_address_2': shipping_address.address_2,
        'shipping_city': shipping_address.city,
        'shipping_state': shipping_address.state,
        'shipping_postcode': shipping_address.postcode,
        'shipping_phone': shipping_address.phone,
        'shipping_email': shipping_address.email,
    }
    const formData = new FormData();
    Object.keys(data).forEach(key => formData.append(key, data[key]));

    try {
        const response = await fetch(plugin_settings.ajaxurl, {
            method: 'POST',
            body: new URLSearchParams(formData),
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            }
        });

        if (!response.ok) {
            throw new Error('Network response was not ok');
        }

        const result = await response.json();

        if (result.data.error) {
            window.alert("Error completing checkout. " + result.data.error);
            var errorInfo = {
                data: data,
                result: result
            };
            document.logBreadIssue('error', errorInfo, '(Checkout) Error in bread_get_options result');
        } else if (result.success) {
            breadOpts = {...result.data };
        } else {
            window.alert("Error completing checkout.");
            var errorInfo = {
                data: data,
                result: result
            };
            document.logBreadIssue('error', errorInfo, '(Checkout) Error in bread_get_options result');
        }
    } catch (error) {
        window.alert("Error completing checkout.");
        var errorInfo = {
            data: data,
            error: error.message
        };
        document.logBreadIssue('error', errorInfo, '(Checkout) Error in bread_get_options call');
    }

    if (breadOpts !== null) {
        return await checkoutWithOpts(breadOpts); // Return Promise
    } else {
        return null;
    }
};

const onApproved = () => {}

const onCheckout = (application, resolve) => {
    if (typeof(application.transactionID) !== 'undefined') {
        resolve(application.transactionID);
    } else {
        // TODO: Handle the case where transactionID is undefined
        resolve(null);
    }
};

const onCustomerClose = (application, reject) => {
    let zoid = document.querySelector('[id^="zoid-checkout-component"]');
    if (zoid) {
        try {
            zoid.remove();
        } catch (e) {}
    }
    reject(application);
    
};

const checkoutWithOpts = (opts) => {
    return new Promise((resolve, reject) => {
        let wasSetup = false;
        let bopisPayload = BreadUtil.createBopisPayload(opts);
        const discounts = (opts.discounts && Array.isArray(opts.discounts)) ? opts.discounts : [];
        const totalDiscountAmount = discounts.reduce((sum, discount) => sum + discount.amount, 0);
        let setup = {
            integrationKey: plugin_settings.integration_key,
            containerID: `${plugin_settings.tenant_prefix}-checkout-embedded`,
            buyer: {
                givenName: opts.billingContact.firstName,
                familyName: opts.billingContact.lastName,
                additionalName: "",
                birthDate: "",
                email: opts.billingContact.email,
                phone: opts.billingContact.phone,
                billingAddress: {
                    address1: opts.billingContact.address,
                    address2: opts.billingContact.address2,
                    country: opts.shippingCountry,
                    locality: opts.billingContact.city,
                    region: opts.billingContact.state,
                    postalCode: opts.billingContact.zip
                },
                shippingAddress: {
                    address1: opts.shippingContact.address,
                    address2: opts.shippingContact.address2,
                    country: opts.shippingCountry,
                    locality: opts.shippingContact.city,
                    region: opts.shippingContact.state,
                    postalCode: opts.shippingContact.zip
                },
            }
        };

        let items = [];
        opts.items.forEach(function(item) {
            let data = {
                name: item.name,
                quantity: item.quantity,
                shippingCost: {
                    value: 0,
                    currency: opts.currency
                },
                shippingDescription: '',
                unitTax: {
                    value: 0,
                    currency: opts.currency
                },
                unitPrice: {
                    currency: opts.currency,
                    value: item.price
                }
            };
            items.push(data);
        });

        let data = [{
            allowCheckout: opts.allowCheckout,
            domID: `${plugin_settings.tenant_prefix}_get_options_checkout_placeholder`,
            order: {
                currency: opts.currency,
                items: items,
                ...bopisPayload,
                totalPrice: {
                    value: opts.customTotal,
                    currency: opts.currency
                },
                subTotal: {
                    value: opts.subTotal,
                    currency: opts.currency
                },
                totalDiscounts: {
                    currency: opts.currency,
                    value: totalDiscountAmount
                },
                totalShipping: {
                    currency: opts.currency,
                    value: (typeof(opts.shippingOptions) !== 'undefined' && opts.shippingOptions.length > 0) ? opts.shippingOptions[0].cost : 0
                },
                totalTax: {
                    currency: opts.currency,
                    value: (typeof(opts.tax) !== 'undefined') ? opts.tax : 0
                }
            }
        }];

        if (!wasSetup) {
            bread_sdk.setup(setup);

            bread_sdk.on('INSTALLMENT:APPLICATION_DECISIONED', onApproved);
            bread_sdk.on('INSTALLMENT:APPLICATION_CHECKOUT', (application) => onCheckout(application, resolve));
            bread_sdk.on('INSTALLMENT:CUSTOMER_CLOSE', (application) => onCustomerClose(application, reject));

            bread_sdk.setEmbedded(opts.setEmbedded);
            bread_sdk.__internal__.setAutoRender(false);
            bread_sdk.registerPlacements(data);
            bread_sdk.setInitMode('manual');
            bread_sdk.init();
            wasSetup = true;
        } else {
            bread_sdk.registerPlacements(data);
            bread_sdk.openExperienceForPlacement(data);
        }
        if (settings.embedded) {
            scroll_to_embedded_checkout();
        }
    });
};

function scroll_to_embedded_checkout() {
    const scrollElement = document.getElementsByClassName(`.${plugin_settings.tenant_prefix}-payments-embedded`);
    if (scrollElement.length) {
        document.body.animate({
            scrollTop: (scrollElement[0].offset().top)
        }, 1000);
    }
};

function createTokenField(container) {
    container.insertAdjacentHTML(
        'beforeend',
        '<input type="hidden" name="bread_tx_token" class="bread_tx_token" value="" />'
    )
}

const Content = (props) => {
    const ref = element.useRef(null);
    const formRef = element.useRef(null);
    const { eventRegistration, emitResponse, billing, shippingData } = props;
    const { onPaymentSetup } = eventRegistration;

    /*
    element.useEffect(() => {
        const unsubscribeValidation = onCheckoutValidation( async() => {
            //fetchBreadOptions(billing, shippingData);
            const appId = await fetchBreadOptions(billing, shippingData);
            console.log("Application ID:", appId);
            if (appId) {
                setApplicationId(appId);
            }
        });
        return () => unsubscribeValidation();
    }, [onCheckoutValidation]);
    */

    element.useEffect(() => {
        const unsubscribe = onPaymentSetup(async() => {
            try {
                const applicationId = await fetchBreadOptions(billing, shippingData);
                if (applicationId) {
                    console.log("Application ID:", applicationId);
                    return {
                        type: emitResponse.responseTypes.SUCCESS,
                        meta: {
                            paymentMethodData: {
                                bread_tx_token: applicationId
                            },
                        }
                    };
                }
            } catch (e) {
                return {
                    type: emitResponse.responseTypes.ERROR,
                    message: e,
                };
            }
        });

        return () => unsubscribe();
    }, [onPaymentSetup, emitResponse.responseTypes.ERROR, emitResponse.responseTypes.SUCCESS]);

    element.useEffect(() => {

        const formElement = document.getElementsByClassName(FORM_ELEMENT)[0];
        if (formElement) {
            createTokenField(formElement);
        }
        /*
        const getFormElement = () => {
            const formElement = document.getElementsByClassName(FORM_ELEMENT)[0];
            if (formElement) {
                formRef.current = formElement;
                console.log('Form Element:', formRef.current);
            }
        };

        // Call the function to get the form element when the component mounts
        getFormElement();
        */
    }, []);
}

/**
 * Determine whether Bread Pay is available for this cart
 *
 * @param {Object} props Incoming props for the component
 *
 * @return {boolean} True if Bread Pay payment method should be displayed as a payment option.
 */
const canMakePayment = ({selectedShippingMethods}) => {
    if (!settings.enabled_for_shipping.length) {
        // Payment method does not limit Bread Pay to specific shipping methods
        return true;
    }

    const selected_methods = Object.values(selectedShippingMethods);
    return settings.enabled_for_shipping.some((shipping_method_id) => {
        return selected_methods.some((selected_method) => {
            return selected_method.includes(shipping_method_id);
        });
    });
};

const Block_Gateway = {
    name: plugin_settings.gateway_token,
    label: label,
    content: Object(window.wp.element.createElement)(Content, null),
    edit: Object(window.wp.element.createElement)(Content, null),
    canMakePayment,
    ariaLabel: label,
    supports: {
        features: settings.supports,
    },
};

window.wc.wcBlocksRegistry.registerPaymentMethod(Block_Gateway);