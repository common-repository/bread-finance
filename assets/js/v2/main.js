/**
 * Bread v3.5.6
 *
 * @author Maritim, Kiprotich
 */

;
(function($, undefined) {

    $.fn.serializeObject = function() {
        "use strict";

        var result = {};
        var extend = function(i, element) {
            var node = result[element.name];

            // If node with same name exists already, need to convert it to an array as it
            // is a multi-value field (i.e., checkboxes)

            if ('undefined' !== typeof node && node !== null) {
                if ($.isArray(node)) {
                    node.push(element.value);
                } else {
                    result[element.name] = [node, element.value];
                }
            } else {
                result[element.name] = element.value;
            }
        };

        $.each(this.serializeArray(), extend);
        return result;
    };

    let parse = function(str) {
        return Function(`'use strict'; return (${str})`)()
    }

    let wasSetup = false;

    let breadController = mwp.controller('woocommerce-gateway-bread', {
        init: function() {
            switch (breadController.local.page_type) {
                case 'category':
                    this.breadCheckoutHandler = new CategoryHandler();
                    break;
                case 'product':
                    this.breadCheckoutHandler = new ProductHandler();
                    break;
                case 'cart_summary':
                    this.breadCheckoutHandler = new CartHandler();
                    break;
                case 'checkout_block':
                case 'checkout':
                    this.breadCheckoutHandler = new CheckoutHandler();
                    break;
                default:
                    this.breadCheckoutHandler = new ProductHandler();
                    break;
            };
            breadController.viewModel = this.breadCheckoutHandler.getViewModel();
            this.breadCheckoutHandler.init();
        }
    });

    let tenantPrefix = breadController.local.tenant_prefix;
    let tenantSdk = breadController.local.tenant_sdk;

    if (undefined === parse(tenantSdk)) {
        return false;
    }

    let bread_sdk = parse(tenantSdk);

    var getConsoleFunc = function(level) {
        switch (level) {
            case 'fatal':
                return console.error;
            case 'error':
                return console.error;
            case 'warning':
                return console.warn;
            case 'info':
                return function(issue) {};
            case 'debug':
                return function(issue) {};
        }
    };

    let TRACKED_TAG_KEYS = [
        'plugin_version',
        'merchant_api_key',
        'tx_id',
    ];

    document.logBreadIssue = function(level, issueInfo, issue) {
        getConsoleFunc(level)(issue);
        var isSentryEnabled = breadController.local.sentry_enabled;
        if (!isSentryEnabled) {
            return;
        }
    };

    $.extend(ko.bindingHandlers, {
        /**
         * The `tenant` data binding attribute contains metadata and the immutable configuration/options for a button
         * instance.
         *
         *  {
         *      "productId": 99,
         *      "productType": "simple",
         *      "opts": {
         *          "buttonId": "bread_checkout_button_99",
         *          "buttonLocation": "product"
         *      }
         *  }
         */
        tenant: {
            init: function(element, valueAccessor) {
                let el = $(element);
                let placeholder = el.html();

                element._reset = function() {
                    el.html(placeholder).removeAttr('data-loaded').css('visibility', 'visible');
                };

                $(document.body).trigger(`${tenantPrefix}_button_bind`, [element, valueAccessor]);
            }
        }
    });


    let CategoryHandler = function() {
        this.$buttons = {};
        this.configs = {};
        this.$button = $(`div.${tenantPrefix}-checkout-button`);
    };

    CategoryHandler.prototype.init = function() {
        let self = this;
        $(document.body).on(`${tenantPrefix}_button_bind`, function(e, element, valueAccessor) {
            breadController.breadCheckoutHandler.onButtonBind(e, element, valueAccessor);
        });

        $(`div.${tenantPrefix}-checkout-button`).each(function() {
            if (self.$buttons[this.id] === undefined) {
                self.$buttons[this.id] = $(this);
            }
        });
    };

    CategoryHandler.prototype.getViewModel = function() {
        return {};
    };

    CategoryHandler.prototype.onButtonBind = function(e, element, valueAccessor) {
        let config = ko.unwrap(valueAccessor());
        this.configs[config.opts.buttonId] = { config: config, loaded: false };
        // Avoid excessive ajax requests by fetching button options only after all buttons have been bound.
        if (Object.keys(this.configs).length === Object.keys(this.$buttons).length) {
            this.renderButtons();
        }
    };

    CategoryHandler.prototype.renderButtons = function() {
        let configs = [],
            self = this;

        /*
         * Ensure we only render the button once per item by setting a `loaded` property. This is needed
         * to support infinite-scrolling on category pages.
         */
        Object.keys(this.configs).forEach(function(key) {
            if (!self.configs[key].loaded) {
                configs[key] = self.configs[key].config;
                self.configs[key].config.loaded = true;
            }
        });

        let request = {
            action: 'bread_get_options',
            page_type: breadController.local.page_type,
            configs: Object.values(configs)
        };

        $.post(breadController.local.ajaxurl, request)
            .done(function(response) {
                if (!response.success) {
                    var errorInfo = Object.assign(
                        request, { response: response },
                    );
                    document.logBreadIssue('error', errorInfo, '(Category) Error in bread_get_options response');
                    return;
                }

                let data = [];
                response.data.forEach(function(opts) {
                    if (opts.items.length > 0) {
                        var itemDetails = {
                            allowCheckout: false,
                            domID: opts.buttonId,
                            order: {
                                currency: opts.currency,
                                items: [{
                                    name: opts.items[0].name,
                                    quantity: opts.items[0].quantity,
                                    shippingCost: { value: 0, currency: opts.currency },
                                    shippingDescription: '',
                                    unitTax: { value: 0, currency: opts.currency },
                                    unitPrice: {
                                        currency: opts.currency,
                                        value: opts.items[0].price
                                    }
                                }],
                                subTotal: { value: opts.items[0].price, currency: opts.currency },
                                totalPrice: { value: opts.items[0].price, currency: opts.currency },
                                totalDiscounts: { value: 0, currency: opts.currency },
                                totalShipping: { value: 0, currency: opts.currency },
                                totalTax: { value: 0, currency: opts.currency }
                            }
                        };
                        data.push(itemDetails);
                    } else {
                        //For variable products/composite/grouped the item count returned is 0
                        if (opts.customTotal > 0) {
                            var itemDetails = {
                                allowCheckout: false,
                                domID: opts.buttonId,
                                order: {
                                    currency: opts.currency,
                                    items: [],
                                    subTotal: { value: opts.customTotal, currency: opts.currency },
                                    totalPrice: { value: opts.customTotal, currency: opts.currency },
                                    totalDiscounts: { value: 0, currency: opts.currency },
                                    totalShipping: { value: 0, currency: opts.currency },
                                    totalTax: { value: 0, currency: opts.currency }
                                }
                            };

                            data.push(itemDetails);
                        }

                    }

                });
                breadController.breadCheckoutHandler.prequalify(data);

            }).fail(function(xhr, status) {
                let errorInfo = Object.assign(
                    request, { status: status, xhr: xhr.responseText },
                );
                console.log(errorInfo);
            });
    };

    CategoryHandler.prototype.prequalify = function(opts) {
        //Init Bread 2.0 SDK
        bread_sdk.setup({
            integrationKey: breadController.local.integration_key
        });

        if (!wasSetup) {
            bread_sdk.on('INSTALLMENT:APPLICATION_DECISIONED', this.onApproved);
            bread_sdk.on('INSTALLMENT:APPLICATION_CHECKOUT', this.onCheckout);

            bread_sdk.registerPlacements(opts);
            bread_sdk.setInitMode('manual');  
            bread_sdk.init();
            wasSetup = true;
        } else {
            bread_sdk.registerPlacements(opts);
        }
    };

    CategoryHandler.prototype.onApproved = function(application) {};

    CategoryHandler.prototype.onCheckout = function(application) {};

    /**
     * 
     * ProductHandler implementation. 
     * `allowCheckout` is disable on 2.0 as there is no callback functionality
     * for shipping/billing details if added via modal  
     * 
     * @returns {mainL#7.ProductHandler}
     */

    let ProductHandler = function() {
        this.$form = $('form.cart');
        this.$button = $(`div.${tenantPrefix}-checkout-button`);
        this.config = {}; // placeholder for button config. populated on bind.
    };

    ProductHandler.prototype.getViewModel = function() {
        return {};
    };

    ProductHandler.prototype.init = function() {
        let self = this;
        $(document.body).on(`${tenantPrefix}_button_bind`, function(e, element, valueAccessor) {
            self.onButtonBind(e, element, valueAccessor);
        });

        $(document).ready(function() {
            self.$form.on('change', function(event) {
                self.onFormChange(event);
            });
        });

        $(`#${tenantPrefix}-btn-cntnr`).mouseover(function() {
            if (self.validateSelections()) $('.button-prevent').hide();
            else $('.button-prevent').show();
        });

        // Variable Products Only: Setup variable product event bindings.
        if ($('form.variations_form').length > 0) {
            self.setupBindingsVariable();
        }

        // Composite Products Only: Setup composite product event bindings.
        if ($('.composite_data').length > 0) {
            self.setupBindingsComposite();
        }
    };

    ProductHandler.prototype.onButtonBind = function(e, element, valueAccessor) {
        this.config = ko.unwrap(valueAccessor());
        this.toggleButton();
    };

    ProductHandler.prototype.setupBindingsVariable = function() {
        var self = this;
        this.$form.on('show_variation', function(variation) {
            self.variation = variation;
            self.toggleButton();
        });

        this.$form.on('reset_data', function() {
            delete self.variation;
            self.toggleButton();
        });
    };

    /**
     * Hook `component_selection_changed` action/event of a composite product and render the Bread
     * checkout button only when a valid configuration has been selected.
     */
    ProductHandler.prototype.setupBindingsComposite = function() {
        $(document).on('wc-composite-initializing', '.composite_data', function(event, composite) {
            breadController.breadCheckoutHandler.composite = composite;

            composite.actions.add_action('component_selection_changed', function() {
                this.toggleButton();
            }, 100, breadController.breadCheckoutHandler);
        });
    };

    ProductHandler.prototype.onFormChange = function(event) {
        let self = this;
        if (this.timeout) window.clearTimeout(this.timeout);

        this.timeout = window.setTimeout(function() {
            self.updateButton();
        }, 1000);

    };

    ProductHandler.prototype.validateSelections = function() {

        var self = this,
            validators = {
                simple: function() {
                    return true;
                },
                grouped: function() {
                    return self.$form.find('input.qty').filter(function(index, element) {
                        return parseInt(element.value) > 0;
                    }).length > 0;
                },
                variable: function() {
                    return self.variation !== undefined;
                },
                composite: function() {
                    return (self.composite && self.composite.api.get_composite_validation_status() === 'pass');
                },
                bundle: function () {
                    return true;
                },
            };

        if (!validators[breadController.local.product_type]) {
            return false;
        }
        this.isValid = validators[breadController.local.product_type]();

        return this.isValid;

    };

    ProductHandler.prototype.toggleButton = function() {

        if (!this.$button[0]) return;

        if (!this.validateSelections()) {
            return this.renderButtonForIncompleteProducts();
        }

        if (this.config.buttonType === 'composite' || this.config.buttonType === 'variable') {
            let iframe = this.$button.find('div > iframe');
            if (iframe.length > 0 && !iframe.parent().is(':visible')) {
                iframe.show();
            }
        }

        this.renderButton();
    };

    ProductHandler.prototype.updateButton = function() {
        if (this.$button[0]) {
            ko.cleanNode(this.$button[0]);
            ko.applyBindings(breadController.viewModel, this.$button[0]);
        }
    };



    ProductHandler.prototype.renderButtonForIncompleteProducts = function() {
        let config = this.config;
        let self = breadController.breadCheckoutHandler;

        $.post(breadController.local.ajaxurl, {
            action: 'bread_get_options',
            config: config,
            page_type: 'product'
        }).done(function(response) {
            if (response.success) {
                let opts = Object.assign(response.data, config.opts);
                let data = {
                    allowCheckout: opts.allowCheckout,
                    domID: opts.buttonId,
                    order: {
                        currency: opts.currency,
                        items: opts.items,
                        subTotal: { value: opts.customTotal, currency: opts.currency },
                        totalPrice: { value: opts.customTotal, currency: opts.currency },
                        totalDiscounts: { value: 0, currency: opts.currency },
                        totalShipping: { value: 0, currency: opts.currency },
                        totalTax: { value: 0, currency: opts.currency }
                    }
                };
                self.prequalify(data);
            } else {
                self.resetButton();
                if (typeof response === 'string')
                    return;
                let errorInfo = { response: response };
                document.logBreadIssue('error', errorInfo, '(PDP) Error in bread_get_options response');
            }
        }).fail(function(xhr, status) {
            self.resetButton();
        });

    };

    ProductHandler.prototype.renderButton = function() {
        let self = breadController.breadCheckoutHandler,
            config = this.config,
            request = this.getPostData('bread_get_options');

        $.post(breadController.local.ajaxurl, request)
            .done(function(response) {
                if (response.success) {
                    let opts = Object.assign(response.data, config.opts);
                    let data = {
                        allowCheckout: opts.allowCheckout,
                        domID: opts.buttonId,
                        order: {
                            currency: opts.currency,
                            items: opts.items,
                            subTotal: { value: opts.customTotal, currency: opts.currency },
                            totalPrice: { value: opts.customTotal, currency: opts.currency },
                            totalDiscounts: { value: 0, currency: opts.currency },
                            totalShipping: { value: 0, currency: opts.currency },
                            totalTax: { value: 0, currency: opts.currency }
                        }
                    };
                    self.prequalify(data);
                } else {
                    this.resetButton();
                    if (typeof response === 'string') return;
                    let errorInfo = Object.assign(
                        request, { response: response },
                    );
                    document.logBreadIssue('error', errorInfo, '(PDP) Error in bread_get_options response');
                }
            }).fail(function(xhr, status) {

            });
    };

    ProductHandler.prototype.getPostData = function(breadAction, shippingContact, billingContact) {
        var data = this.$form.serializeObject();

        data['add-to-cart'] = this.$form[0]['add-to-cart'].value;
        data['action'] = breadAction;
        data['config'] = this.config;
        data['page_type'] = breadController.local.page_type;

        if (shippingContact !== null) {
            data['shipping_contact'] = shippingContact;
        }

        if (billingContact !== null) {
            data['billing_contact'] = billingContact;
        }

        return data;
    };

    ProductHandler.prototype.resetButton = function() {
        if (this.$button.attr('data-loaded')) {
            this.$button[0]._reset();
        }
    };

    ProductHandler.prototype.prequalify = function(opts) {
        bread_sdk.setup({
            integrationKey: breadController.local.integration_key
        });

        if (!wasSetup) {
            bread_sdk.on('INSTALLMENT:APPLICATION_DECISIONED', this.onApproved);
            bread_sdk.on('INSTALLMENT:APPLICATION_CHECKOUT', this.onCheckout);
            bread_sdk.registerPlacements([opts]);
            bread_sdk.setInitMode('manual');
            bread_sdk.init();
            wasSetup = true;
        } else {
            bread_sdk.registerPlacements([opts]);
        }
    };

    ProductHandler.prototype.onApproved = function(application) {};

    ProductHandler.prototype.onCheckout = function(application) {};

    //Cart page checkout
    let CartHandler = function() {
        this.$form = $('form.woocommerce-cart-form');
        this.$button = $(`div.${tenantPrefix}-checkout-button`);
    };

    CartHandler.prototype.init = function() {
        let self = this;

        $(document.body).on(`${tenantPrefix}_button_bind`, function(e, element, valueAccessor) {
            breadController.breadCheckoutHandler.onButtonBind(e, element, valueAccessor);
        });

        this.$form.on('change', function(event) {
            breadController.breadCheckoutHandler.onFormChange(event);
        });

        $(document.body).on('updated_wc_div', function(event) {
            breadController.breadCheckoutHandler.updateButton();
        });

        // updated_shipping_method fired only on cart page
        $(document.body).on('updated_shipping_method', function(event) {
            this.timeout = window.setTimeout(function() {
                this.$button = $(`div.${tenantPrefix}-checkout-button`);
                breadController.breadCheckoutHandler.updateButton();
            }, 1000);
        });
    };

    CartHandler.prototype.getViewModel = function() {
        return {};
    };

    CartHandler.prototype.onButtonBind = function(e, element, valueAccessor) {
        this.config = ko.unwrap(valueAccessor());
        this.renderButton();
    };

    CartHandler.prototype.onFormChange = function(event) {

        if (this.timeout) window.clearTimeout(this.timeout);

        if ($(event.target).hasClass('qty')) {
            this.timeout = window.setTimeout(function() {
                breadController.breadCheckoutHandler.updateButton();
            }, 100);
        }

    };

    CartHandler.prototype.renderButton = function() {
        let form = $('form.woocommerce-cart-form');
        var self = breadController.breadCheckoutHandler,
            config = this.config,
            request = {
                action: 'bread_get_options',
                page_type: 'cart_summary',
                config: config,
                form: form.serializeArray()
            };
        $.post(breadController.local.ajaxurl, request)
            .done(function(response) {
                if (response.success) {
                    let opts = Object.assign(response.data, config.opts);

                    let items = [];
                    response.data.items.forEach(function(item) {
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

                    let data = {
                        allowCheckout: opts.allowCheckout,
                        domID: opts.buttonId,
                        order: {
                            currency: opts.currency,
                            items,
                            totalPrice: {
                                value: opts.customTotal,
                                currency: opts.currency
                            },
                            subTotal: {
                                value: opts.customTotal,
                                currency: opts.currency
                            },
                            totalDiscounts: {
                                currency: opts.currency,
                                value: 0, //(typeof opts.discounts != undefined) ? opts.discounts.amount : 0,
                            },
                            totalShipping: {
                                currency: opts.currency,
                                value: 0
                            },
                            totalTax: {
                                currency: opts.currency,
                                value: 0
                            }
                        }

                    };

                    breadController.breadCheckoutHandler.prequalify(data);

                } else {
                    self.resetButton();
                    let errorInfo = Object.assign(
                        request, { response: response },
                    );
                    document.logBreadIssue('error', errorInfo, '(Cart) Error in bread_get_options response');
                }
            })
            .fail(function(xhr, status) {
                self.resetButton();
                var errorInfo = Object.assign(
                    request, { status: status, xhr: xhr.responseText },
                );
                document.logBreadIssue('error', errorInfo, '(Cart) Error in bread_get_options call');
            });
    };

    CartHandler.prototype.prequalify = function(opts) {
        bread_sdk.setup({
            integrationKey: breadController.local.integration_key
        });

        if (!wasSetup) {
            bread_sdk.on('INSTALLMENT:APPLICATION_DECISIONED', this.onApproved);
            bread_sdk.on('INSTALLMENT:APPLICATION_CHECKOUT', this.onCheckout);

            bread_sdk.registerPlacements([opts]);
            bread_sdk.setInitMode('manual');  
            bread_sdk.init();
            wasSetup = true;
        } else {
            bread_sdk.registerPlacements([opts]);
        }
    };

    CartHandler.prototype.updateButton = function() {
        if (this.$button[0]) {
            ko.cleanNode(this.$button[0]);
            ko.applyBindings(breadController.viewModel, this.$button[0]);
        }
    };

    CartHandler.prototype.resetButton = function() {
        if (this.$button.attr('data-loaded')) {
            this.$button[0]._reset();
        }
    };

    CartHandler.prototype.onApproved = function(application) {};

    CartHandler.prototype.onCheckout = function(application) {};

    //Main Checkout page
    let CheckoutHandler = function() {
        this.$form = $('form.checkout, form#order_review');
        this.flags = ['bread-g-recaptcha-response'];
    };

    CheckoutHandler.prototype.init = function() {
        var self = this,
            isOrderPayForm = $('form#order_review').length > 0;

        $('form.checkout').on('change', 'input[name^="shipping_method"]', self.checkShippingAndHidePayment);
        setTimeout(function() {
            self.checkShippingAndHidePayment();
        }, 1000);
        
        if (isOrderPayForm) {
            self.$form.on('submit', function() {
                if ($('#payment_method_' + breadController.local.gateway_token).is(':checked')) {
                    /*  If the hidden input `bread_tx_token` exists, checkout has been completed and the form should be submitted */
                    var isCompletedBreadCheckout = self.$form.find('input[name="bread_tx_token"]').length > 0;
                    if (isCompletedBreadCheckout) return true;

                    self.doBreadCheckoutForOrderPay();
                    return false;
                }
            });
        } else {
            self.$form.on('checkout_place_order_' + breadController.local.gateway_token, function() {
                /*  If the hidden input `bread_tx_token` exists, checkout has been completed and the form should be submitted */
                let isCompletedBreadCheckout = self.$form.find('input[name="bread_tx_token"]').length > 0;
                if (isCompletedBreadCheckout) return true;

                self.doBreadCheckout();
                return false;
            });
        }
    };

    /**
     * Determine whether Bread Pay is available for this cart in
     * legacy shortcode based checkout page
     */
    CheckoutHandler.prototype.checkShippingAndHidePayment = function() {
        const selected_method =
          $('input[name^="shipping_method"]').val() ||
          $('input[name^="shipping_method"]:checked').val();
        
        // dont hide payment if enabled_for_shipping is not set
        if (breadController.local.enabled_for_shipping.length === 0) {
            return false;
        }

        if (!selected_method || breadController.local.page_type === 'checkout_block') {
            return false;
        }
        const payment_method = 'li.payment_method_' + breadController.local.gateway_token;
        const enabled_for_shipping = breadController.local.enabled_for_shipping.some((shipping_method_id) => {
            return selected_method.includes(shipping_method_id);
        });
        if (!enabled_for_shipping) {
            console.info(`${breadController.local.gateway_token} is not available for ${selected_method}`);
            $(payment_method).hide();
        } else {
            $(payment_method).show();
        }
    }

    CheckoutHandler.prototype.getViewModel = function() {
        return {};
    };

    CheckoutHandler.prototype.doBreadCheckout = function() {
        this.addProcessingOverlay();

        let self = this,
            formIsValid = false,
            breadOpts = null,
            form = this.$form.serialize();

        $.ajax({
            type: 'POST',
            url: wc_checkout_params.checkout_url + '&bread_validate=true',
            data: form,
            dataType: 'json',
            async: false,
            success: function(result) {
                if (result.result === 'success') {
                    formIsValid = true;
                    self.removeProcessingOverlay();
                } else {
                    self.removeProcessingOverlay();
                    self.wc_submit_error(result.messages);
                    var errorInfo = {
                        form: form,
                        result: result
                    };
                    document.logBreadIssue('error', errorInfo, '(Checkout) Invalid checkout form');
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                self.removeProcessingOverlay();
                self.wc_submit_error('<div class="woocommerce-error">' + errorThrown + '</div>');
                var errorInfo = {
                    form: form,
                    jqXHR: jqXHR.responseText,
                    textStatus: textStatus,
                    errorThrown: errorThrown
                };
                document.logBreadIssue('error', errorInfo, '(Checkout) Error in validate checkout form call');
            }
        });
        if (formIsValid) {
            let data = {
                action: 'bread_get_options',
                page_type: 'checkout'
            };

            self.$form.serializeArray().forEach(function(item) {
                data[item.name] = item.value;
            });

            $.ajax({
                type: 'POST',
                url: breadController.local.ajaxurl,
                data: data,
                async: false,
                success: function(result) {
                    if (result.data.error) {
                        window.alert("Error completing checkout. " + result.data.error);
                        var errorInfo = {
                            data: data,
                            result: result
                        };
                        document.logBreadIssue('error', errorInfo, '(Checkout) Error in bread_get_options result');
                    } else if (result.success) {
                        breadOpts = Object.assign(result.data);
                    } else {
                        window.alert("Error completing checkout.");
                        var errorInfo = {
                            data: data,
                            result: result
                        };
                        document.logBreadIssue('error', errorInfo, '(Checkout) Error in bread_get_options result');
                    }
                },
                error: function(jqXHR, textStatus, errorThrown) {
                    window.alert("Error completing checkout.");
                    var errorInfo = {
                        data: data,
                        jqXHR: jqXHR.responseText,
                        textStatus: textStatus,
                        errorThrown: errorThrown
                    };
                    document.logBreadIssue('error', errorInfo, '(Checkout) Error in bread_get_options call');
                }
            });
        }
        if (breadOpts !== null) {
            breadController.breadCheckoutHandler.checkoutWithOpts(breadOpts);
        }
    };

    CheckoutHandler.prototype.checkoutWithOpts = function(opts) {
        let self = this;
        let bopisPayload = BreadUtil.createBopisPayload(opts);
        const discounts = (opts.discounts && Array.isArray(opts.discounts)) ? opts.discounts : [];
        const totalDiscountAmount = discounts.reduce((sum, discount) => sum + discount.amount, 0);

        let setup = {
            integrationKey: breadController.local.integration_key,
            containerID: `${tenantPrefix}-checkout-embedded`,
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

        //Configure checkout options for sdk placements
        let data = [{
            allowCheckout: opts.allowCheckout,
            domID: `${tenantPrefix}_get_options_checkout_placeholder`,
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
            //Init Bread SDK
            bread_sdk.setup(setup);

            bread_sdk.on('INSTALLMENT:APPLICATION_DECISIONED', this.onApproved);
            bread_sdk.on('INSTALLMENT:APPLICATION_CHECKOUT', this.onCheckout);
        
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
        
        if (opts.setEmbedded) {
            this.scroll_to_embedded_checkout();
        }
    };

    CheckoutHandler.prototype.onApproved = function(application) {};

    CheckoutHandler.prototype.onCheckout = function(application) {
        /*
         * 
         * Complete an order via API call.
         * If you have hooks attached to wc_create_order they might not work with this 
         * implementation
         * 
         $.post(breadController.local.ajaxurl, {
         action: 'bread_complete_checkout',
         tx_id: application.transactionID,
         form: breadController.breadCheckoutHandler.$form.serializeArray()
         }).done(function (response) {
         if (response.success && response.data.redirect) {
         window.location.href = response.data.redirect;
         } else {
         window.alert("Error completing checkout.");
         }
         }).fail(function (xhr, status) {
         window.alert("Error completing checkout.");
         });
         */

        var tokenField = breadController.breadCheckoutHandler.$form.find('input[name="bread_tx_token"]');

        if (typeof(application.transactionID) === 'undefined') {
            var errorInfo = {
                err: application
            };
            document.logBreadIssue('error', errorInfo, '(Checkout) Error in done callback');
            window.alert("Error completing checkout.");
            return breadController.breadCheckoutHandler.$form.remove('input[name="bread_tx_token"]');
        }

        if (tokenField.length > 0) {
            tokenField.val(application.transactionID);
        } else {
            breadController.breadCheckoutHandler.$form.append(
                $('<input />').attr('name', 'bread_tx_token').attr('type', 'hidden').val(application.transactionID)
            );
        }

        for (var flag of breadController.breadCheckoutHandler.flags) {
            $('<input>', {
                type: 'hidden',
                id: flag,
                name: flag,
                value: 'true'
            }).appendTo(breadController.breadCheckoutHandler.$form);
        }
        breadController.breadCheckoutHandler.$form.submit();

    };

    CheckoutHandler.prototype.addProcessingOverlay = function() {
        /*
         * Borrowed from plugins/woocommerce/assets/js/frontend/checkout.js->submit()
         */
        this.$form.addClass('processing').block({
            message: null,
            overlayCSS: {
                background: '#fff',
                opacity: 0.6
            }
        });
    };

    CheckoutHandler.prototype.removeProcessingOverlay = function() {
        this.$form.removeClass('processing').unblock();
    };

    CheckoutHandler.prototype.wc_submit_error = function(error_message) {
        $('.woocommerce-NoticeGroup-checkout, .woocommerce-error, .woocommerce-message').remove();
        this.$form.prepend('<div class="woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout">' + error_message + '</div>');
        this.$form.removeClass('processing').unblock();
        this.$form.find('.input-text, select, input:checkbox').trigger('validate').blur();
        this.wc_scroll_to_notices();
        $(document.body).trigger('checkout_error');
    };

    CheckoutHandler.prototype.wc_scroll_to_notices = function() {
        var scrollElement = $('.woocommerce-NoticeGroup-updateOrderReview, .woocommerce-NoticeGroup-checkout'),
            isSmoothScrollSupported = 'scrollBehavior' in document.documentElement.style;

        if (!scrollElement.length) {
            scrollElement = $('.form.checkout');
        }

        if (scrollElement.length) {
            if (isSmoothScrollSupported) {
                scrollElement[0].scrollIntoView({
                    behavior: 'smooth'
                });
            } else {
                $('html, body').animate({
                    scrollTop: (scrollElement.offset().top - 100)
                }, 1000);
            }
        }
    };

    CheckoutHandler.prototype.scroll_to_embedded_checkout = function() {
        var scrollElement = $(`#${tenantPrefix}-checkout-embedded`);
        if (scrollElement.length) {
            $('html, body').animate({
                scrollTop: (scrollElement.offset().top)
            }, 1000);
        }
    };


})(jQuery);