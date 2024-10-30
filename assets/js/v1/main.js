/*
Version:   3.0.0
Released:  
*/

;(function ($, undefined) {

    "use strict";
    var breadController = mwp.controller('woocommerce-gateway-bread', {
        init: function () {
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
                case 'checkout':
                    this.breadCheckoutHandler = new CheckoutHandler();
                    break;
                default:
                    this.breadCheckoutHandler = new ProductHandler();
                    break;
            }

            breadController.viewModel = this.breadCheckoutHandler.getViewModel();

            this.breadCheckoutHandler.init();

            if (!bread.apiKey) bread.setAPIKey(breadController.local.bread_api_key);
        },

        debug: function ($err) {
            if (breadController.local.debug) {
                //console.log($err);
            }
        }

    });

    var optsByButtonId = {};
    var willModifyOpts = undefined;
    var BreadWC = {
        // The provided callback should accept the generic opts for a button and callback.
        // The callback needs to be invoked with the modified options in order to enable the button.
        setWillModifyOpts: function(f) {
          if (typeof f !== "function") {
            document.logBreadIssue('warning', {f: f}, 'BreadWC.setWillModifyOpts requires a function as the sole argument');
            return;
          }
          willModifyOpts = f;
        },
        optsForButtonId: function(id) {
          if (typeof optsByButtonId[id] !== "undefined") {
            return optsByButtonId[id];
          }
          return {};
        }
    };
    window.BreadWC = BreadWC;

    function checkoutWithOpts(opts) {
         
        var checkoutCall = breadController.local.page_type === 'checkout' ? bread.showCheckout : bread.checkout;

        if (typeof willModifyOpts === "function") {
            willModifyOpts(opts, function(err, newOpts) {
                if (err !== undefined) {
                    var errorInfo = {
                        err: err,
                        opts,
                        newOpts: newOpts,
                        willModifyOpts: willModifyOpts,
                        source: breadController.local.page_type
                    };
                    document.logBreadIssue('warning', errorInfo, 'Not adding behavior to Bread button [%s]', newOpts.buttonId);
                    return;
                } else {
                    optsByButtonId[opts.buttonId] = newOpts;
                    checkoutCall(newOpts);
                }
            });
        } else {
            optsByButtonId[opts.buttonId] = opts;
            checkoutCall(opts);
        }
    };

    var getConsoleFunc = function (level) {
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

    var TRACKED_TAG_KEYS = [
        'plugin_version',
        'merchant_api_key',
        'tx_id'
    ];

    document.logBreadIssue = function (level, issueInfo, issue) {
        getConsoleFunc(level)(issue);
        var isSentryEnabled = breadController.local.sentry_enabled;

        if (!isSentryEnabled) {
            return;
        }
    };
    var MAX_SECS_BEFORE_ABORT = 5;
    var TIMEOUT_INTERVAL = 100;
    var RETRIES = MAX_SECS_BEFORE_ABORT * 1000 / TIMEOUT_INTERVAL;
    var ERROR_PREFIX = 'Bread Integration Error: Could not setup promotional label for SplitPay. Reason: ';
    var INTEGRATION_ERROR = 'Could not create Bread SplitPay Promotional Label within 5 seconds. Please verify that the provided selector is valid';

    var setupSplitpay = function (opts, selector, includeInstallments) {
        var args = Array.prototype.slice.call(arguments);

        var retryCount = 0;
        var retry = window.setInterval(function(){
            try {
                if (window.bread) {
                    window.clearInterval(retry);
                    splitpayPromo(opts, selector, includeInstallments);
                }
                if (retryCount < RETRIES) retryCount++;
                else throw new Error(INTEGRATION_ERROR);

            } catch (err) {
                var errorInfo = {
                    error: err,
                    args: args,
                    retryCount: retryCount,
                    bread: window.bread,
                    source: breadController.local.page_type
                };
                document.logBreadIssue('error', errorInfo, "ERROR_PREFIX" + err)
                window.clearInterval(retry);
            }
        }, TIMEOUT_INTERVAL);
    };


    var splitpayPromo = function (opts, selector, includeInstallments) {
        
        if (window.bread === undefined ) {
            return;
        }
       
        var total = null;
        if (opts.hasOwnProperty('customTotal')) {
            total = opts.customTotal;
        } else if (opts.hasOwnProperty('items')) {
            total = opts.items.reduce(function(sum, i) {
                return Math.round(i.price * i.quantity) + sum;
            }, 0);
        } else {
            var errorInfo = {
                args: Array.prototype.slice.call(arguments), 
                bread: window.bread,
                source: breadController.local.page_type
            };
            document.logBreadIssue('warn', errorInfo, '[Bread-SplitPay] failed to calclulate total');
            return;
        }

        if (total > 100000) {
            return;
        }

        bread.showSplitPayPromo({
            selector: selector,
            total: total,
            includeInstallments: includeInstallments,
            openModalOnClick: true,
            opts: opts
        });
    };

    $.extend(ko.bindingHandlers, {
        /**
         * The `bread` data binding attribute contains metadata and the immutable configuration/options for a button
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
        bread: {
            init: function (element, valueAccessor) {
                var el = $(element);
                var placeholder = el.html();

                element._reset = function () {
                    el.html(placeholder).removeAttr('data-loaded').css('visibility', 'visible');
                };

                $(document.body).trigger('bread_button_bind', [element, valueAccessor]);
            }
        }
    });

    var CategoryHandler = function () {
        this.$buttons = {};
        this.configs = {};
    };

    CategoryHandler.prototype.init = function () {

        var self = this;

        $(document.body).on('bread_button_bind', function (e, element, valueAccessor) {
            breadController.breadCheckoutHandler.onButtonBind(e, element, valueAccessor);
        });

        $('div.bread-checkout-button').each(function () {
            if (self.$buttons[this.id] === undefined) {
                self.$buttons[this.id] = $(this);
            }
        });

    };

    CategoryHandler.prototype.getViewModel = function () {
        return {};
    };

    CategoryHandler.prototype.onButtonBind = function (e, element, valueAccessor) {
        var config = ko.unwrap(valueAccessor());

        this.configs[config.opts.buttonId] = {config: config, loaded: false};

        // Avoid excessive ajax requests by fetching button options only after all buttons have been bound.
        if (Object.keys(this.configs).length === Object.keys(this.$buttons).length) {
            this.renderButtons();
        }

    };

    CategoryHandler.prototype.renderButtons = function () {

        var configs = [],
            self = this;

        /*
         * Ensure we only render the button once per item by setting a `loaded` property. This is needed
         * to support infinite-scrolling on category pages.
         */
        Object.keys(this.configs).forEach(function (key) {
            if (!self.configs[key].loaded) {
                configs[key] = self.configs[key].config;
                self.configs[key].config.loaded = true;
            }
        });

        var request = {
            action: 'bread_get_options',
            source: breadController.local.page_type,
            configs: Object.values(configs)
        };

        $.post(breadController.local.ajaxurl, request)
            .done(function (response) {
                var self = breadController.breadCheckoutHandler;

                if (!response.success) {
                    var errorInfo = Object.assign(
                        request,
                        { response: response },
                    );
                    document.logBreadIssue('error', errorInfo, '(Category) Error in bread_get_options response');
                    return;
                }

                response.data.forEach(function (opts) {
                    if (opts.healthcareMode) {
                        ['items', 'discounts', 'shippingOptions'].forEach(function(el) {
                            delete opts[el];
                          });
                        opts.allowCheckout = false;
                    }
                    
                    var total;
                    if(opts.items) {
                        total = opts.items.reduce(function(sum, current) {
                            return sum + current.price;
                        }, 0);
                    } else if(opts.customTotal) {
                        total = opts.customTotal;
                    } else {
                        total = 0;
                    }

                    var _opts = Object.assign(
                        opts,
                        configs[opts.buttonId].opts,
                        self.getBreadCallbacks()
                    );

                    // if targeted financing is enabled, check price of each product on category
                    // opts.tfThreshold only exists if targeted financing is turned on in the Bread settings
                    if(opts.tfThreshold && total < opts.tfThreshold) {
                        delete _opts.financingProgramId;
                    }
                    checkoutWithOpts(_opts);

                });

            })
            .fail(function (xhr, status) {
                var errorInfo = Object.assign(
                    request, 
                    { status: status, xhr: xhr.responseText },
                );
                document.logBreadIssue('error', errorInfo, '(Category) Error in bread_get_options call');
            });

    };

    CategoryHandler.prototype.resetButton = function () {
        if (this.$buttons[this.opts.buttonId].attr('data-loaded')) {
            this.$buttons[this.opts.buttonId][0]._reset();
        }
    };

    CategoryHandler.prototype.fail = function (xhr, error) {
        this.resetButton();
        breadController.debug(error);
    };

    /**
     * Define the Bread checkout callback functions for category pages.
     */
    CategoryHandler.prototype.getBreadCallbacks = function () {
        var self = this;
        return {
            onCustomerOpen: function (err, data, callback) {
                self.opts = data.opts;
                callback(data);
            },
            onCustomerClose: function (err, custData) {
                delete self.opts;
                if (!err) {
                    $.post(breadController.local.ajaxurl, {
                        action: 'bread_set_qualstate',
                        customer_data: custData
                    });
                }
            },
            calculateTax: function (shippingContact, billingContact, callback) {
                $.post(breadController.local.ajaxurl, {
                    action: 'bread_calculate_tax',
                    button_opts: {items: breadController.breadCheckoutHandler.opts.items},
                    shipping_contact: shippingContact,
                    billing_contact: billingContact
                })
                    .done(function (response) {
                        if (response.success) {
                            callback(null, response.data.tax);
                        } else {
                            callback(response.data);
                            self.fail(null, response.data);
                        }
                    })
                    .fail(function (xhr, status) {
                        callback(status);
                        self.fail(null, response.data);
                    });
            },
            calculateShipping: function (shippingContact, callback) {
                $.post(breadController.local.ajaxurl, {
                    action: 'bread_calculate_shipping',
                    button_opts: {items: breadController.breadCheckoutHandler.opts.items},
                    shipping_contact: shippingContact
                })
                    .done(function (response) {
                        if (response.success) {
                            callback(null, response.data.shippingOptions);
                        } else {
                            callback(response.data);
                            self.fail(null, response.data);
                        }
                    })
                    .fail(function (xhr, status) {
                        callback(status);
                        self.fail(xhr, status);
                    });
            },
            done: function (err, txToken) {
                if (err) {
                    self.fail(null, err);
                    return window.alert("Error completing checkout.");
                }

                $.post(breadController.local.ajaxurl, {
                    action: 'bread_complete_checkout',
                    tx_id: txToken
                }).done(function (response) {
                    if (response.success && response.data.redirect) {
                        window.location.href = response.data.redirect;
                    } else {
                        self.fail(null, response.data);
                        window.alert("Error completing checkout.");
                    }
                }).fail(function (xhr, status) {
                    self.fail(xhr, status);
                    window.alert("Error completing checkout.");
                });
            }
        };
    };

    var ProductHandler = function () {
        this.$form = $('form.cart');
        this.$button = $('div.bread-checkout-button');
        this.config = {};   // placeholder for button config. populated on bind.
    };

    ProductHandler.prototype.init = function () {

        $(document.body).on('bread_button_bind', function (e, element, valueAccessor) {
            breadController.breadCheckoutHandler.onButtonBind(e, element, valueAccessor);
        });
        
        $(document).ready(function () {
            breadController.breadCheckoutHandler.$form.on('change', function (event) {
                breadController.breadCheckoutHandler.onFormChange(event);
            });
        });
        
        var self = this;
        $('#bread-btn-cntnr').mouseover(function() {
            if (self.validateSelections()) $('.button-prevent').hide();
            else $('.button-prevent').show();
        });

        // Variable Products Only: Setup variable product event bindings.
        if ($('form.variations_form').length > 0) {
            this.setupBindingsVariable();
        }

        // Composite Products Only: Setup composite product event bindings.
        if ($('.composite_data').length > 0) {
            this.setupBindingsComposite();
        }

    };

    ProductHandler.prototype.getViewModel = function () {
        return {};
    };

    /*
     * When knockout.js binds to the button element, trigger the button setup/rendering function for
     * the current product-type. This will also be called when certain form values change that require
     * an update to the Bread button options.
     *
     * simple products: Render the button immediately.
     * composite products: The button can't be rendered until valid component selections have been made.
     *                     Wire-up the event handlers for `component_selection_changed`.
     */
    ProductHandler.prototype.onButtonBind = function (e, element, valueAccessor) {
        this.config = ko.unwrap(valueAccessor());
        this.toggleButton();
    };

    /*
     * Update the Bread button options in response to changes on `form.cart`.
     */
    ProductHandler.prototype.onFormChange = function (event) {

        if (this.timeout) window.clearTimeout(this.timeout);

        this.timeout = window.setTimeout(function () {
            breadController.breadCheckoutHandler.updateButton();
        }, 1000);

    };

    ProductHandler.prototype.setupBindingsVariable = function () {
        var self = this;
        this.$form.on('show_variation', function (variation) {
            self.variation = variation;
            self.toggleButton();
        });

        this.$form.on('reset_data', function () {
            delete self.variation;
            self.toggleButton();
        });
    };

    /**
     * Hook `component_selection_changed` action/event of a composite product and render the Bread
     * checkout button only when a valid configuration has been selected.
     */
    ProductHandler.prototype.setupBindingsComposite = function () {
        $(document).on('wc-composite-initializing', '.composite_data', function (event, composite) {
            breadController.breadCheckoutHandler.composite = composite;

            composite.actions.add_action('component_selection_changed', function () {
                this.toggleButton();
            }, 100, breadController.breadCheckoutHandler);
        });
    };

    ProductHandler.prototype.validateSelections = function () {

        var self = this,
            validators = {
                simple: function () {
                    return true;
                },

                grouped: function () {
                    return self.$form.find('input.qty').filter(function (index, element) {
                        return parseInt(element.value) > 0;
                    }).length > 0;
                },

                variable: function () {
                    return self.variation !== undefined;
                },

                composite: function () {
                    return (self.composite && self.composite.api.get_composite_validation_status() === 'pass');
                }
            };

        if(! validators[breadController.local.product_type]) {
            return false;
        }
        this.isValid = validators[breadController.local.product_type]();

        return this.isValid;

    };

    ProductHandler.prototype.getPostData = function (breadAction, shippingContact, billingContact) {
        var data = this.$form.serializeObject();

        data['add-to-cart'] = this.$form[0]['add-to-cart'].value;
        data['action'] = breadAction;
        data['config'] = this.config;
        data['source'] = breadController.local.page_type;

        if (shippingContact !== null) {
            data['shipping_contact'] = shippingContact;
        }

        if (billingContact !== null) {
            data['billing_contact'] = billingContact;
        }

        return data;
    };

    ProductHandler.prototype.renderButton = function () {
        var self = breadController.breadCheckoutHandler,
            url = this.$form.attr('action') || window.location.href,
            config = this.config,
            request = this.getPostData('bread_get_options'); 
        $.post(breadController.local.ajaxurl, request)
            .done(function (response) {
                if (response.success) {
                    var opts = Object.assign(response.data, config.opts, self.getBreadCallbacks());
                    var customTotal = opts.customTotal;
                    if (response.data.healthcareMode) {
                        ['items', 'discounts', 'shippingOptions'].forEach(function(el) {
                            delete opts[el];
                        });
                        opts.allowCheckout = false;
                    } else {
                        delete opts.customTotal;
                    }
                    
                    //With Woocommerce Product Add-Ons plugin, customTotal contains the consolidated amount
                    if (typeof opts.addons !== 'undefined' && opts.addons.length > 0) {
                        opts.customTotal = customTotal;
                    }
                    opts.allowSplitPayCheckout = false;

                    checkoutWithOpts(opts);
                    setupSplitpay(opts, '.splitpay-clickable-price', true);
                    setupSplitpay(opts, '.splitpay-clickable-button', false);
                } else {
                    self.resetButton();
                    if(typeof response === 'string') return; 
                    var errorInfo = Object.assign(
                        request,
                        { response: response },
                    );
                    document.logBreadIssue('error', errorInfo, '(PDP) Error in bread_get_options response');
                }
            })
            .fail(function (xhr, status) {
                self.resetButton();
                var errorInfo = Object.assign(
                    request,
                    { status: status, xhr: xhr.responseText },
                ); 
                document.logBreadIssue('error', errorInfo, '(PDP) Error in bread_get_options call');
            });
    };

    ProductHandler.prototype.toggleButton = function () {

        if (!this.$button[0]) return;

        if (!this.validateSelections()) {
            return this.renderButtonForIncompleteProducts();
        }

        if (this.config.buttonType === 'composite' || this.config.buttonType === 'variable') {
            var iframe = this.$button.find('div > iframe');
            if (iframe.length > 0 && !iframe.parent().is(':visible')) {
                iframe.show();
            }
        }

        this.renderButton();
    };

    /**
     * Unbind/Rebind the bread button to trigger an update of the Bread button options.
     */
    ProductHandler.prototype.updateButton = function () {
        if (this.$button[0]) {
            ko.cleanNode(this.$button[0]);
            ko.applyBindings(breadController.viewModel, this.$button[0]);
        }
    };

    /**
     * Renders button with asLowAs pricing displayed for products with incomplete configurations. 
     * An overlay will message to complete necessary configurations and prevent the button from being clicked. 
     * Sets allowCheckout to false as a precaution.
     */
    ProductHandler.prototype.renderButtonForIncompleteProducts = function () {
        var config = this.config;
        var self = breadController.breadCheckoutHandler;
        $.post(breadController.local.ajaxurl, {
            action: 'bread_get_options',
            config: config,
            source: 'product'
        })
            .done(function (response) {
                var self = breadController.breadCheckoutHandler;
                
                if (response.success) {
                    var opts = Object.assign(response.data, config.opts, self.getBreadCallbacks());
                    if (response.data.healthcareMode) {
                        ['items', 'discounts', 'shippingOptions'].forEach(function(el) {
                            delete opts[el];
                          });
                    }
                    opts.allowCheckout = false;
                    opts.allowSplitPayCheckout = false;

                    checkoutWithOpts(opts);
                    setupSplitpay(opts, '.splitpay-clickable-price', true);
                    setupSplitpay(opts, '.splitpay-clickable-button', false);
                } else {
                    self.resetButton();
                }
            })
            .fail(function (xhr, status) {
                self.resetButton();
            });
    };

    ProductHandler.prototype.resetButton = function () {
        if (this.$button.attr('data-loaded')) {
            this.$button[0]._reset();
        }
    };

    /**
     * Define the Bread checkout callback functions for product pages.
     */
    ProductHandler.prototype.getBreadCallbacks = function () {
        var self = this;
        return {
            onCustomerOpen: function (err, data, callback) {
                self.opts = data.opts;
                callback(data);
            },
            onCustomerClose: function (err, custData) {
                delete self.opts;
                if (!err) {
                    $.post(breadController.local.ajaxurl, {
                        action: 'bread_set_qualstate',
                        customer_data: custData
                    });
                }
            },
            calculateTax: function (shippingContact, billingContact, callback) {
                var url = self.$form.attr('action') || window.location.href,
                    request = self.getPostData('bread_calculate_tax', shippingContact, billingContact);

                $.post(url, request)
                    .done(function (response) {
                        if (response.success) {
                            callback(null, response.data.tax);
                        } else {
                            callback(response.data);
                            self.resetButton();
                            var errorInfo = Object.assign(
                                request,
                                { response: response },
                            );
                            document.logBreadIssue('error', errorInfo, '(PDP) Error in bread_calculate_tax response');
                        }
                    })
                    .fail(function (xhr, status) {
                        callback(status);
                        self.resetButton();
                        var errorInfo = Object.assign(
                            request,
                            { status: status, xhr: xhr.responseText },
                        );
                        document.logBreadIssue('error', errorInfo, '(PDP) Error in bread_calculate_tax call');
                    });

            },
            calculateShipping: function (shippingContact, callback) {
                var url = self.$form.attr('action') || window.location.href,
                    request = self.getPostData('bread_calculate_shipping', shippingContact);

                $.post(url, request)
                    .done(function (response) {
                        if (response.success) {
                            callback(null, response.data.shippingOptions);
                        } else {
                            callback(response.data);
                            self.resetButton();
                            var errorInfo = Object.assign(
                                request,
                                { response: response },
                            );
                            document.logBreadIssue('error', errorInfo, '(PDP) Error in bread_calculate_shipping response');
                        }
                    })
                    .fail(function (xhr, status) {
                        callback(status);
                        self.resetButton();
                        var errorInfo = Object.assign(
                            request,
                            { status: status, xhr: xhr.responseText },
                        );
                        document.logBreadIssue('error', errorInfo, '(PDP) Error in bread_calculate_shipping call');
                    });
            },
            done: function (err, txToken) {
                if (err) {
                    self.resetButton();
                    var errorInfo = {
                        err: err,
                        txToken: txToken
                    };
                    document.logBreadIssue('error', errorInfo, '(PDP) Error in done callback');
                    return window.alert("Error completing checkout.");
                }

                var request = {
                    action: 'bread_complete_checkout',
                    tx_id: txToken,
                    form: breadController.breadCheckoutHandler.$form.serializeArray()
                };
                $.post(breadController.local.ajaxurl, request)
                    .done(function (response) {
                        if (response.success && response.data.redirect) {
                            window.location.href = response.data.redirect;
                        } else {
                            self.resetButton();
                            var errorInfo = Object.assign(
                                request,
                                { response: response },
                            );
                            document.logBreadIssue('error', errorInfo, '(PDP) Error in bread_complete_checkout response');
                            var errorMessage = (response.data && response.data.spDecline) ? response.data.response : "Error completing checkout";
                            window.alert(errorMessage);
                        }
                    })
                    .fail(function (xhr, status) {
                        self.resetButton();
                        var errorInfo = Object.assign(
                            request,
                            { status: status, xhr: xhr.responseText },
                        );
                        document.logBreadIssue('error', errorInfo, '(PDP) Error in bread_complete_checkout call');
                        window.alert("Error completing checkout.");
                    });

            }
        };
    };


    var CartHandler = function () {
        this.$form = $('form.woocommerce-cart-form');
        this.$button = $('div.bread-checkout-button');
    };

    CartHandler.prototype.init = function () {

        var self = this;

        $(document.body).on('bread_button_bind', function (e, element, valueAccessor) {
            breadController.breadCheckoutHandler.onButtonBind(e, element, valueAccessor);
        });

        this.$form.on('change', function (event) {
            breadController.breadCheckoutHandler.onFormChange(event);
        });

        $(document.body).on('updated_wc_div', function (event) {
            breadController.breadCheckoutHandler.updateButton();
        });

        $(document.body).on('updated_shipping_method', function (event) {
            this.$button = $('div.bread-checkout-button');
            breadController.breadCheckoutHandler.updateButton();
        });

    };

    CartHandler.prototype.getViewModel = function () {
        return {};
    };

    CartHandler.prototype.onButtonBind = function (e, element, valueAccessor) {
        this.config = ko.unwrap(valueAccessor());
        this.renderButton();
    };

    CartHandler.prototype.onFormChange = function (event) {

        if (this.timeout) window.clearTimeout(this.timeout);

        if ($(event.target).hasClass('qty')) {
            this.timeout = window.setTimeout(function () {
                breadController.breadCheckoutHandler.updateButton();
            }, 100);
        }

    };

    CartHandler.prototype.renderButton = function () {
        var self = breadController.breadCheckoutHandler,
            config = this.config, 
            request = {
                action: 'bread_get_options',
                source: 'cart_summary',
                config: config,
                form: this.$form.serializeArray()
            };

        $.post(breadController.local.ajaxurl, request)
            .done(function (response) {
                if (response.success) {
                    var opts = Object.assign(response.data, config.opts, self.getBreadCallbacks());
                    if ( response.data.healthcareMode ) {
                        ['items', 'discounts', 'shippingOptions'].forEach(function(el) {
                            delete opts[el];
                          });
                        opts.allowCheckout = false;
                    }
                    opts.allowSplitPayCheckout = false;
                    
                    checkoutWithOpts(opts);
                    setupSplitpay(opts, '.splitpay-clickable-button', false);
                } else {
                    self.resetButton();
                    var errorInfo = Object.assign(
                        request,
                        { response: response },
                    );
                    document.logBreadIssue('error', errorInfo, '(Cart) Error in bread_get_options response');
                }
            })
            .fail(function (xhr, status) {
                self.resetButton();
                var errorInfo = Object.assign(
                    request,
                    { status: status, xhr: xhr.responseText },
                );
                document.logBreadIssue('error', errorInfo, '(Cart) Error in bread_get_options call');
            });
    };

    CartHandler.prototype.updateButton = function () {
        if (this.$button[0]) {
            ko.cleanNode(this.$button[0]);
            ko.applyBindings(breadController.viewModel, this.$button[0]);
        }
    };

    CartHandler.prototype.resetButton = function () {
        if (this.$button.attr('data-loaded')) {
            this.$button[0]._reset();
        }
    };

    CartHandler.prototype.getBreadCallbacks = function () {
        var self = this;
        return {
            calculateTax: function (shippingContact, billingContact, callback) {
                var request = {
                    action: 'bread_calculate_tax',
                    source: breadController.local.page_type,
                    shipping_contact: shippingContact,
                    billing_contact: billingContact
                };

                $.post(breadController.local.ajaxurl, request)
                    .done(function (response) {
                        if (response.success) {
                            callback(null, response.data.tax);
                        } else {
                            callback(response.data);
                            self.resetButton();
                            var errorInfo = Object.assign(
                                request,
                                { response: response },
                            );
                            document.logBreadIssue('error', errorInfo, '(Cart) Error in bread_calculate_tax response');
                        }
                    })
                    .fail(function (xhr, status) {
                        callback(status);
                        self.resetButton();
                        var errorInfo = Object.assign(
                            request,
                            { status: status, xhr: xhr.responseText },
                        );
                        document.logBreadIssue('error', errorInfo, '(Cart) Error in bread_calculate_tax call');
                    });
            },
            calculateShipping: function (shippingContact, callback) {
                var request = {
                    action: 'bread_calculate_shipping',
                    source: breadController.local.page_type,
                    shipping_contact: shippingContact
                };

                $.post(breadController.local.ajaxurl, request)
                    .done(function (response) {
                        if (response.success) {
                            callback(null, response.data.shippingOptions);
                        } else {
                            callback(response.data);
                            self.resetButton();
                            var errorInfo = Object.assign(
                                request,
                                { response: response },
                            );
                            document.logBreadIssue('error', errorInfo, '(Cart) Error in bread_calculate_shipping response');
                        }
                    })
                    .fail(function (xhr, status) {
                        callback(status);
                        self.resetButton();
                        var errorInfo = Object.assign(
                            request,
                            { status: status, xhr: xhr.responseText },
                        );
                        document.logBreadIssue('error', errorInfo, '(Cart) Error in bread_calculate_shipping call');
                    });
            },
            done: function (err, txToken) {
                if (err) {
                    self.resetButton();
                    var errorInfo = {
                        err: err,
                        txToken: txToken
                    };
                    document.logBreadIssue('error', errorInfo, '(Cart) Error in done callback');
                    return window.alert("Error completing checkout.");
                }

                var request = {
                    action: 'bread_complete_checkout',
                    tx_id: txToken
                };
                $.post(breadController.local.ajaxurl, request)
                    .done(function (response) {
                        if (response.success && response.data.redirect) {
                            window.location.href = response.data.redirect;
                        } else {
                            self.resetButton();
                            var errorInfo = Object.assign(
                                request,
                                { response: response },
                            );
                            document.logBreadIssue('error', errorInfo, '(Cart) Error in bread_complete_checkout response');
                            var errorMessage = (response.data && response.data.spDecline) ? response.data.response : "Error completing checkout";
                            window.alert(errorMessage);
                        }
                    })
                    .fail(function (xhr, status) {
                        self.resetButton();
                        var errorInfo = Object.assign(
                            request,
                            { status: status, xhr: xhr.responseText },
                        );
                        document.logBreadIssue('error', errorInfo, '(Cart) Error in bread_complete_checkout call');
                        window.alert("Error completing checkout.");
                    });
            }
        }
    };
    var CheckoutHandler = function () {
        this.$form = $('form.checkout, form#order_review');
    };

    CheckoutHandler.prototype.init = function () {
        var self = this,
            isOrderPayForm = $('form#order_review').length > 0;
            
        $(document).on('updated_checkout', function(){
            //To show installment/PayOverTime only labels uncomment below
            //self.changeLabel();
        });
        if (isOrderPayForm) {
            this.$form.on('submit', function() {
                if ($( '#payment_method_' + breadController.local.gateway_token).is( ':checked' )) {
                    /*  If the hidden input `bread_tx_token` exists, checkout has been completed and the form should be submitted */
                    var isCompletedBreadCheckout = self.$form.find('input[name="bread_tx_token"]').length > 0;
                    if (isCompletedBreadCheckout) return true;

                    self.doBreadCheckoutForOrderPay();
                    return false;
                }
            })
        } else {
            this.$form.on('checkout_place_order_' + breadController.local.gateway_token, function () {
                /*  If the hidden input `bread_tx_token` exists, checkout has been completed and the form should be submitted */
                var isCompletedBreadCheckout = self.$form.find('input[name="bread_tx_token"]').length > 0;
                if (isCompletedBreadCheckout) return true;

                self.doBreadCheckout();
                return false;
            });
        }

    };

    CheckoutHandler.prototype.changeLabel = function() {
    
        var INSTALLMENTS_BLUE = '#5156ea';
        var SPLITPAY_GREEN = '#57c594';

        var retryCount = 0;
        var retry = window.setInterval(function() {
            try {
                if(window.bread === undefined){
                    return;
                }
                var label = jQuery('#payment_method_bread_finance').next('label').attr("for", "payment_method_bread_finance");
                label.text('');
                if(breadController.local.show_splitpay_label) {
                    label.append(' Pay Over Time with ' +
                            '<span style="color: ' + INSTALLMENTS_BLUE + '; font-weight: 500;">Installments</span> or ' +
                            '<span style="color: ' + SPLITPAY_GREEN + '; font-weight: 500;">SplitPay</span>');
                
                } else {
                    label.append(' Pay Over Time with ' +
                            '<span style="color: ' + INSTALLMENTS_BLUE + '; font-weight: 500;">Installments</span>');
                }
                if (retryCount < RETRIES) retryCount++;
                else throw new Error(INTEGRATION_ERROR);

            } catch (err) {
                var errorInfo = {
                    error: err,
                    retryCount: retryCount,
                    bread: window.bread,
                    source: breadController.local.page_type
                };
                document.logBreadIssue('error', errorInfo, ERROR_PREFIX + err);
                window.clearInterval(retry);
            }
        }, TIMEOUT_INTERVAL);
    };

    CheckoutHandler.prototype.getViewModel = function () {
        return {};
    };

    CheckoutHandler.prototype.doBreadCheckout = function () {
        this.addProcessingOverlay();
        var self = this,
            formIsValid = false,
            breadOpts = null,
            form = this.$form.serialize();
        /*
         * Checkout form validation & Bread options ajax call must run synchronously in order for the
         * call to bread.showCheckout to happen in the context of the original button-click event.
         * Otherwise the Bread dialog will be treated as a pop-up and blocked by some browsers.
         */
        $.ajax({
            type: 'POST',
            url: wc_checkout_params.checkout_url + '&bread_validate=true',
            data: form,
            dataType: 'json',
            async: false,
            success: function (result) {
                if (result.result === 'success') {
                    formIsValid = true;
                } else {
                    self.removeProcessingOverlay();
                    self.wc_submit_error(result.messages);
                    var errorInfo = {
                        form: form,
                        result: result,
                    };
                    document.logBreadIssue('error', errorInfo, '(Checkout) Invalid checkout form');
                }
            },
            error: function (jqXHR, textStatus, errorThrown) {
                self.removeProcessingOverlay();
                self.wc_submit_error('<div class="woocommerce-error">' + errorThrown + '</div>');
                var errorInfo = {
                    form: form,
                    jqXHR: jqXHR.responseText,
                    textStatus: textStatus,
                    errorThrown: errorThrown,
                };
                document.logBreadIssue('error', errorInfo, '(Checkout) Error in validate checkout form call');
            }
        }
        );

        if (formIsValid) {
            var data = {
                action: 'bread_get_options',
                source: 'checkout'
            };

            self.$form.serializeArray().forEach(function (item) {
                data[item.name] = item.value;
            });

            $.ajax({
                type: 'POST',
                url: breadController.local.ajaxurl,
                data: data,
                async: false,
                success: function (result) {
                    if (result.data.error) {
                        window.alert("Error completing checkout. " + result.data.error);
                        var errorInfo = {
                            data: data,
                            result: result
                        };
                        document.logBreadIssue('error', errorInfo, '(Checkout) Error in bread_get_options result');
                    } else if (result.success) {
                        breadOpts = Object.assign(result.data, self.getBreadCallbacks());
                    } else {
                        window.alert("Error completing checkout.");
                        var errorInfo = {
                            data: data,
                            result: result
                        };
                        document.logBreadIssue('error', errorInfo, '(Checkout) Error in bread_get_options result');
                    }
                },
                error: function (jqXHR, textStatus, errorThrown) {
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
            if (breadOpts.healthcareMode) {
                ['items', 'discounts', 'shippingOptions', 'tax'].forEach(function(el) {
                    delete breadOpts[el];
                });
            }
            checkoutWithOpts(breadOpts);
        }

    };

    CheckoutHandler.prototype.doBreadCheckoutForOrderPay = function () {
        this.addProcessingOverlay();

        var self      = this,
            breadOpts = null,
            data      = { action: 'bread_get_order_pay_opts' };

        $.ajax({
            type: 'POST',
            url: breadController.local.ajaxurl,
            data: data,
            async: false,
            success: function (result) {
                if (result.success) {
                    breadOpts = Object.assign(result.data.options, self.getBreadCallbacks());
                } else {
                    self.removeProcessingOverlay();
                    self.wc_submit_error(result.data);
                    var errorInfo = {
                        data: data,
                        result: result,
                    };
                    document.logBreadIssue('error', errorInfo, '(Order-Pay) Error in bread_get_order_pay_opts result');
                }
            }, 
            error: function (jqXHR, textStatus, errorThrown) {
                self.removeProcessingOverlay();
                self.wc_submit_error("An error occurred. Please try again.");
                var errorInfo = {
                    data: data,
                    jqXHR: jqXHR.responseText,
                    status: textStatus,
                    errorThrown: errorThrown,
                };
                document.logBreadIssue('error', errorInfo, '(Order-Pay) Error in bread_get_order_pay_opts call');
            },
        });
        
        if (breadOpts !== null) {
            if (breadOpts.healthcareMode) {
                ['items', 'discounts', 'shippingOptions', 'tax'].forEach(function(el) {
                    delete breadOpts[el];
                });
            }
            checkoutWithOpts(breadOpts);
        }
    };

    CheckoutHandler.prototype.getBreadCallbacks = function () {
        var self = this;
        return {
            onCustomerClose: function (err, custData) {
                self.removeProcessingOverlay();
            },
            done: function (err, tx_token) {
                var tokenField = self.$form.find('input[name="bread_tx_token"]');

                self.removeProcessingOverlay();

                if (err) {
                    var errorInfo = {
                        err: err,
                        txToken: tx_token
                    };
                    document.logBreadIssue('error', errorInfo, '(Checkout) Error in done callback');
                    return self.$form.remove('input[name="bread_tx_token"]');
                }

                if (tokenField.length > 0) {
                    tokenField.val(tx_token);
                } else {
                    self.$form.append(
                        $('<input />').attr('name', 'bread_tx_token').attr('type', 'hidden').val(tx_token)
                    );
                }
                self.$form.submit();
            }
        };
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

    CheckoutHandler.prototype.wc_submit_error = function (error_message) {
        $('.woocommerce-NoticeGroup-checkout, .woocommerce-error, .woocommerce-message').remove();
        this.$form.prepend('<div class="woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout">' + error_message + '</div>');
        this.$form.removeClass('processing').unblock();
        this.$form.find('.input-text, select, input:checkbox').trigger('validate').blur();
        this.wc_scroll_to_notices();
        $(document.body).trigger('checkout_error');
    };

    CheckoutHandler.prototype.wc_scroll_to_notices = function () {
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

})(jQuery);