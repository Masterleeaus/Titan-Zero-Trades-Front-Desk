tzfdformjQuery = "undefined" != typeof tzfdformjQuery ? tzfdformjQuery : jQuery, tzfdformjQuery((function() {
    var e;
    (e = tzfdformjQuery).extend({
        stringifyXX: function(t) {
            var i = function(e) {
                    var t = /[\\\"\x00-\x1f\x7f-\x9f\u00ad\u0600-\u0604\u070f\u17b4\u17b5\u200c-\u200f\u2028-\u202f\u2060-\u206f\ufeff\ufff0-\uffff]/g,
                        i = {
                            "\b": "\\b",
                            "\t": "\\t",
                            "\n": "\\n",
                            "\f": "\\f",
                            "\r": "\\r",
                            '"': '\\"',
                            "\\": "\\\\"
                        };
                    return t.lastIndex = 0, t.test(e) ? e.replace(t, (function(e) {
                        var t = i[e];
                        return "string" == typeof t ? t : "\\u" + ("0000" + e.charCodeAt(0).toString(16)).slice(-4)
                    })) : e
                },
                a = typeof t;
            if ("object" != a || null === t) return "string" == a && (t = '"' + t + '"'), String(t);
            var s, o, l = [],
                n = t && t.constructor == Array;
            for (s in t) "function" != (a = typeof(o = t[s])) && ("string" == a ? o = '"' + i(o) + '"' : "object" == a && null !== o && (o = e.stringifyXX(o)), l.push((n ? "" : '"' + s + '":') + String(o)));
            return (n ? "[" : "{") + String(l) + (n ? "]" : "}")
        }
    })
})), $tzfdformloadedflag = !0, tzfdformjQuery = "undefined" != typeof tzfdformjQuery ? tzfdformjQuery : jQuery, tzfdformjQuery((function() {
    (function($) {
        $.tzfdform = $.tzfdform || {}, $.tzfdform.objName = "tzfdformjQuery", $.tzfdform.typeList = [], $.tzfdform.categoryList = [], $.tzfdform.controls = {}, $.tzfdform.preview = function(e) {
                var t = $(e.form);
                t.attr("target", "formpopup").attr("onsubmit", (function(e) {
                    var t = .8 * screen.width,
                        i = .7 * screen.height,
                        a = screen.width / 2 - t / 2,
                        s = screen.height / 2 - i / 2,
                        o = window.open("", "formpopup", "resizeable,scrollbars,width=" + t + ",height=" + i + ",left=" + a + ",top=" + s);
                    $(e).removeAttr("onsubmit"), o.focus()
                })), $('<input type="hidden" name="preview" value="1" />').appendTo(t), t[0].submit(), t.attr("target", "_self").find('input[name="preview"]').remove()
            }, $.tzfdform.htmlEncode = function(e) {
                return e = (e = $("<div/>").text(e).html()).replace(/"/g, "&quot;")
            }, $.tzfdform.textareaEncode = function(e) {
                return String(null == e ? "" : e).replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;")
            }, $.tzfdform.escape_symbol = function(e) {
                return e.replace(/([\^\$\-\.\,\[\]\(\)\/\\\*\?\+\!\{\}])/g, "\\$1")
            }, $.tzfdform.parseVal = function(e, t, i) {
                if ("" == e) return 0;
                e += "", t = new RegExp($.tzfdform.escape_symbol(void 0 === t ? "," : t), "g"), i = new RegExp($.tzfdform.escape_symbol(void 0 === i ? "." : i), "g");
                var a = e.replace(t, "").replace(i, ".").replace(/\s/g, ""),
                    s = /[+-]?((\d+(\.\d+)?)|(\.\d+))/.exec(a);
                return s ? 1 * s[0] : '"' + e.replace(/'/g, "\\'").replace(/\$/g, "") + '"'
            }, $.tzfdform.showErrorMssg = function(e) {
                $(".form-builder-error-messages").empty().append($('<div class="error-text"></div>').text(e))
            }, $.fn.tzfdform = function(options) {
                var opt = $.extend({}, {
                        pub: !1,
                        identifier: "",
                        title: ""
                    }, options, !0),
                    typeList = $.tzfdform.typeList,
                    categoryList = $.tzfdform.categoryList;
                $.tzfdform.getNameByIdFromType = function(e) {
                    for (var t = 0, i = typeList.length; t < i; t++)
                        if (typeList[t].id == e) return typeList[t].name;
                    return ""
                };
                for (var i = 0, h = typeList.length; i < h; i++) {
                    var category_id = typeList[i].control_category;
                    void 0 === categoryList[category_id] ? categoryList[category_id] = {
                        title: "",
                        description: "",
                        typeList: []
                    } : void 0 === categoryList[category_id].typeList && (categoryList[category_id].typeList = []), categoryList[category_id].typeList.push(i)
                }
                for (var i in categoryList)
                    if ($("#tzfdtabs-1").append('<div style="clear:both;"></div><div>' + categoryList[i].title + "</div><hr />"), void 0 === categoryList[i].description || /^\s*$/.test(categoryList[i].description) || $("#tzfdtabs-1").append('<div style="clear:both;"></div><div class="category-description">' + categoryList[i].description + "</div>"), void 0 !== categoryList[i].typeList)
                        for (var j = 0, k = categoryList[i].typeList.length; j < k; j++) {
                            var index = categoryList[i].typeList[j];
                            $("#tzfdtabs-1").append('<div class="button ' + (0 != j && 1 != j && 2 != j && 3 != j || i > 1 ? "n" : "itemForm") + '  width40" id="' + typeList[index].id + '">' + typeList[index].name + "</div>")
                        }
                $("#tzfdtabs-1").append('<div class="clearer"></div>'), $(".button").button();
                var items = [],
                    fieldsIndex = {},
                    calculatedFields = {},
                    selected = -3;
                $.tzfdform.editItem = function(e) {
                    selected = e;
                    try {
                        $("#tzfdtabs-2").html(items[e].showAllSettings())
                    } catch (e) {}
                    items[e].editItemEvents()
                }, $.tzfdform.removeItem = function(e) {
                    void 0 !== items[e].remove && items[e].remove(), items.splice(e, 1);
                    for (var t = 0, i = items.length; t < i; t++) items[t].index = t;
                    selected = -2, $("#tabs").tabs("option", "active", 0), $.tzfdform.reloadItems()
                }, $.tzfdform.duplicateItem = function(e) {
                    for (var t = 0, i = 0, a = items.length; i < a; i++) n1 = parseInt(items[i].name.replace(/fieldname/g, "")), n1 > t && (t = n1);
                    if ($("#field-" + items[e].index).parents(".fields").length) {
                        i = $("#field-" + items[e].index).parents(".fields").attr("id").replace("field-", "");
                        void 0 !== items[i].duplicateItem && items[i].duplicateItem(items[e].name, "fieldname" + (t + 1))
                    }
                    items.splice(1 * e + 1, 0, $.extend(!0, {}, items[e], {
                        name: "fieldname" + (t + 1)
                    }));
                    for (i = 1 * e + 1, a = items.length; i < a; i++) items[i].index = i;
                    $("#tabs").tabs("option", "active", 0), $.tzfdform.reloadItems()
                }, $.tzfdform.editForm = function() {
                    $("#tzfdtabs-3").html(theForm.showAllSettings()), selected = -1, $("#fTitle").keyup((function() {
                        theForm.title = $(this).val(), $.tzfdform.reloadItems()
                    })), $("#fEvalEquations").click((function() {
                        theForm.evalequations = $(this).is(":checked") ? 1 : 0, $.tzfdform.reloadItems()
                    })), $("#fAutofocus").click((function() {
                        theForm.autofocus = $(this).is(":checked"), $.tzfdform.reloadItems()
                    })), $("#fAutocomplete").click((function() {
                        theForm.autocomplete = $(this).is(":checked") ? 1 : 0, $.tzfdform.reloadItems()
                    })), $("#fDescription").keyup((function() {
                        theForm.description = $(this).val(), $.tzfdform.reloadItems()
                    })), $("#fLayout").change((function() {
                        theForm.formlayout = $(this).val(), $.tzfdform.reloadItems()
                    })), $("#fTemplate").change((function() {
                        theForm.formtemplate = $(this).val();
                        var e = $.tzfdform.showSettings.formTemplateDic[theForm.formtemplate],
                            t = "",
                            i = "";
                        void 0 !== e && (void 0 !== e.thumbnail && (t = '<img src="' + e.thumbnail + '">'), void 0 !== e.description && (i = e.description)), $("#fTemplateThumbnail").html(t), $("#fTemplateDescription").html(i), $.tzfdform.reloadItems()
                    }))
                }, $.tzfdform.reloadItems = function() {
                    for (var e = 0, t = $.tzfdform.showSettings.formlayoutList.length; e < t; e++) $("#fieldlist" + opt.identifier).removeClass($.tzfdform.showSettings.formlayoutList[e].id);
                    $("#fieldlist" + opt.identifier).addClass(theForm.formlayout), $("#formheader" + opt.identifier).html(theForm.display()), $("#fieldlist" + opt.identifier).html(""), -1 == parseInt(selected) ? $(".fform").addClass("ui-selected") : $(".fform").removeClass("ui-selected"), fieldsIndex = {}, calculatedFields = {};
                    for (e = 0, t = items.length; e < t; e++) items[e].index = e, fieldsIndex[items[e].name] = e, "fCalculated" == items[e].ftype && (calculatedFields[items[e].name] = e), $("#fieldlist" + opt.identifier).append(items[e].display()), e == selected ? ($("#field" + opt.identifier + "-" + e).addClass("ui-selected"), 1 != $("#tabs").tabs("option", "active") && $.tzfdform.editItem(e)) : $("#field" + opt.identifier + "-" + e).removeClass("ui-selected"), $("#field" + opt.identifier + "-" + e + " .remove").click((function() {
                        $.tzfdform.removeItem($(this).parent().attr("id").replace("field" + opt.identifier + "-", ""))
                    })), $("#field" + opt.identifier + "-" + e + " .copy").click((function() {
                        $.tzfdform.duplicateItem($(this).parent().attr("id").replace("field" + opt.identifier + "-", ""))
                    }));
                    for (e = 0, t = items.length; e < t; e++) void 0 !== items[e].after_show && items[e].after_show();
                    items.length > 0 && ($(".fields").mouseover((function(e) {
                        $(this).addClass("ui-over"), e.stopPropagation()
                    })).mouseout((function(e) {
                        $(this).removeClass("ui-over"), e.stopPropagation()
                    })).click((function(e) {
                        e.stopPropagation(), $.tzfdform.editItem($(this).attr("id").replace("field" + opt.identifier + "-", "")), $("#fieldlist .ui-selected").removeClass("ui-selected"), $(this).addClass("ui-selected"), $("#tabs").tabs("option", "active", 1)
                    })), $(".field").focus((function() {
                        $(this).blur()
                    }))), $(".fform").mouseover((function() {
                        $(this).addClass("ui-over")
                    })).mouseout((function() {
                        $(this).removeClass("ui-over")
                    })).click((function(e) {
                        e.stopPropagation(), $("#tabs").tabs("option", "active", 2), $.tzfdform.editForm(), $(this).siblings().removeClass("ui-selected"), $(this).addClass("ui-selected")
                    })), ffunct.saveData("form_structure");
                    var i = "",
                        a = $("#cu_user_email_field").attr("def").split(",");
                    for (e = 0, t = items.length; e < t; e++) {
                        "femail" != (n = items[e]).ftype && "femailds" != n.ftype || (i += '<option value="' + n.name + '" ' + (-1 != $.inArray(n.name, a) ? "selected" : "") + ">" + n.name + " (" + $.tzfdform.htmlEncode(n.title) + ")</option>")
                    }
                    $("#cu_user_email_field").html(i);
                    i = "";
                    var s = $('[name="paypal_recurrent_field"]').attr("def");
                    for (e = 0, t = items.length; e < t; e++) {
                        "fradio" != (n = items[e]).ftype && "fdropdown" != n.ftype || (i += '<option value="' + n.name + '" ' + (n.name == s ? "selected" : "") + ">" + n.name + " (" + $.tzfdform.htmlEncode(n.title) + ")</option>")
                    }
                    if ($('[name="paypal_recurrent_field"]').html(i), $("#fp_destination_emails_field").length > 0 && $("#fp_destination_emails_field").is("select")) {
                        for (i = "", e = 0, t = items.length; e < t; e++) {
                            i += '<option value="' + (n = items[e]).name + '" ' + (n.name == $("#fp_destination_emails_field").attr("def") ? "selected" : "") + ">" + n.name + "(" + $.tzfdform.htmlEncode(n.title) + ")</option>"
                        }
                        $("#fp_destination_emails_field").html(i)
                    }
                    if ($("#request_cost").length > 0 && $("#request_cost").is("select")) {
                        i = "";
                        var o = $("#request_cost").attr("def");
                        for (e = 0, t = items.length; e < t; e++) {
                            i += '<option value="' + (n = items[e]).name + '" ' + (n.name == o ? "selected" : "") + ">" + n.name + "(" + $.tzfdform.htmlEncode(n.title) + ")</option>"
                        }
                        $("#request_cost").html(i)
                    }
                    if ($("#paypal_price_field").length > 0) {
                        var l = $("#paypal_price_field").attr("def");
                        for (i = '<option value="" ' + ("" == l ? "selected" : "") + "> ---- No ---- </option>", e = 0, t = items.length; e < t; e++) {
                            var n;
                            i += '<option value="' + (n = items[e]).name + '" ' + (n.name == l ? "selected" : "") + ">" + $.tzfdform.htmlEncode(n.title) + "</option>"
                        }
                        $("#paypal_price_field").html(i)
                    }
                };
                var fform = function() {};
                $.extend(fform.prototype, {
                    title: "Untitled Form",
                    description: "This is my form. Please fill it out. It's awesome!",
                    formlayout: "top_aligned",
                    formtemplate: "",
                    evalequations: 1,
                    autocomplete: 1,
                    autofocus: !1,
                    display: function() {
                        return '<div class="fform" id="field"><div class="arrow ui-icon ui-icon-play "></div><h1>' + $.tzfdform.htmlEncode(this.title) + "</h1><span>" + $.tzfdform.htmlEncode(this.description) + "</span></div>"
                    },
                    showAllSettings: function() {
                        for (var e = "", t = '<option value="">Use default template</option>', i = "", a = "", s = "", o = 0; o < $.tzfdform.showSettings.formlayoutList.length; o++) e += '<option value="' + $.tzfdform.showSettings.formlayoutList[o].id + '" ' + ($.tzfdform.showSettings.formlayoutList[o].id == this.formlayout ? "selected" : "") + ">" + $.tzfdform.showSettings.formlayoutList[o].name + "</option>";
                        for (var o in $.tzfdform.showSettings.formTemplateDic) {
                            if (/^\s*$/.test(o)) break;
                            s = "", $.tzfdform.showSettings.formTemplateDic[o].prefix == this.formtemplate && (s = "SELECTED", void 0 !== $.tzfdform.showSettings.formTemplateDic[o].thumbnail && (i = '<img src="' + $.tzfdform.showSettings.formTemplateDic[o].thumbnail + '">'), void 0 !== $.tzfdform.showSettings.formTemplateDic[o].description && (a = $.tzfdform.showSettings.formTemplateDic[o].description)), t += '<option value="' + $.tzfdform.showSettings.formTemplateDic[o].prefix + '" ' + s + ">" + $.tzfdform.htmlEncode($.tzfdform.showSettings.formTemplateDic[o].title) + "</option>"
                        }
                        return '<div><label>Form Name</label><input class="large" name="fTitle" id="fTitle" value="' + $.tzfdform.htmlEncode(this.title) + '" /></div><div><label>Description</label><textarea class="large" name="fDescription" id="fDescription">' + $.tzfdform.textareaEncode(this.description) + '</textarea></div><div><label>Label Placement</label><br /><select name="fLayout" id="fLayout" class="large">' + e + '</select></div><div><label><input type="checkbox" name="fAutofocus" id="fAutofocus" ' + (this.autofocus ? "CHECKED" : "") + ' /> Auto-focus booking form when the page is loaded</label></div><div><label><input type="checkbox" name="fAutocomplete" id="fAutocomplete" ' + (this.autocomplete ? "CHECKED" : "") + " /> Enable autocompletion</label></div>" + (void 0 !== $.tzfdform.controls.fCalculated ? '<div><label><input type="checkbox" name="fEvalEquations" id="fEvalEquations" ' + (this.evalequations ? "CHECKED" : "") + " /> Eval dynamically the equations associated to the calculated fields</label></div>" : "") + '<div><label>Form Template</label><br /><select name="fTemplate" id="fTemplate" class="large">' + t + '</select></div><br /><div><span id="fTemplateThumbnail" style="float:left;padding-right:10px;">' + i + '</span><span  id="fTemplateDescription" style="float:left;">' + a + "</span></div>"
                    }
                });
                var theForm = new fform;
                $("#tzfdform").click((function(e) {
                    e.stopPropagation(), selected = -2, $(".fform").removeClass("ui-selected"), $("#fieldlist .ui-selected").removeClass("ui-selected"), $("#tabs").tabs("option", "active", 0)
                })), $("#fieldlist" + opt.identifier).sortable({
                    connectWith: ".ui-sortable",
                    items: ".fields",
                    update: function(e, t) {
                        var i, a = items.length;
                        for (i = 0; i < a && !t.item.hasClass(items[i].name); i++);
                        if ("fieldlist" == t.item.parent().attr("id")) {
                            var s = parseInt(t.item.index());
                            items.splice(s, 0, items.splice(i, 1)[0]), $.tzfdform.reloadItems()
                        } else items = items.concat(items.splice(i, 1))
                    }
                }), $("#tabs").tabs({
                    activate: function(e, t) {
                        switch ($(this).tabs("option", "active")) {
                            case 0:
                                $(".fform").removeClass("ui-selected");
                                break;
                            case 1:
                                $(".fform").removeClass("ui-selected"), selected < 0 && $("#tzfdtabs-2").html("<b>No Field Selected</b><br />Please click on a field in the form preview on the right to change its properties.");
                                break;
                            case 2:
                                $(".fields").removeClass("ui-selected"), $(".fform").addClass("ui-selected"), $.tzfdform.editForm()
                        }
                    }
                });
                var ffunct = {
                    getFieldsIndex: function() {
                        return fieldsIndex
                    },
                    getCalculatedFields: function() {
                        return calculatedFields
                    },
                    getItems: function() {
                        return items
                    },
                    addItem: function(id) {
                        var obj = new $.tzfdform.controls[id](),
                            fBuild = this,
                            n = 0;
                        obj.init();
                        for (var i = 0, h = items.length; i < h; i++) n1 = parseInt(items[i].name.replace(/fieldname/g, "")), n1 > n && (n = n1);
                        return n++, obj.fBuild = fBuild, $.extend(obj, {
                            name: "fieldname" + n
                        }), selected >= 0 ? (items.splice(1 * selected + 1, 0, obj), void 0 !== items[selected].addItem ? items[selected].addItem(obj.name) : selected++) : (selected = items.length, items[selected] = obj), $.tzfdform.reloadItems(), obj
                    },
                    saveData: function(e) {
                        var t, i = $.stringifyXX(items),
                            a = $.stringifyXX(theForm),
                            s = "The entered data includes invalid characters. Please, if you are copying and pasting from another platform, be sure the data not include invalid characters.";
                        "undefined" != typeof global_varible_save_data && $(".form-builder-error-messages").html("");
                        try {
                            null != $.parseJSON(i) && null != $.parseJSON(a) ? (t = "[" + i + ",[" + a + "]]", $("#" + e).val(t), $("#form_structure_crc").val(t.length)) : $.tzfdform.showErrorMssg(s)
                        } catch (e) {
                            $.tzfdform.showErrorMssg(s)
                        }
                        global_varible_save_data = !0
                    },
                    loadData: function(form_structure, available_templates) {
                        var structure = $.parseJSON($("#" + form_structure).val()),
                            templates = void 0 === available_templates ? null : $.parseJSON($("#" + available_templates).val()),
                            fBuild = this;
                        if (structure && 2 == structure.length) {
                            items = [];
                            for (var i = 0; i < structure[0].length; i++) {
                                var obj = new $.tzfdform.controls[structure[0][i].ftype]();
                                obj = $.extend(!0, {}, obj, structure[0][i]), obj.name = obj.name + opt.identifier, obj.form_identifier = opt.identifier, obj.fBuild = fBuild, items[items.length] = obj
                            }
                            theForm = new fform, theForm = $.extend(theForm, structure[1][0]), $.tzfdform.reloadItems()
                        }
                        templates && ($.tzfdform.showSettings.formTemplateDic = templates)
                    },
                    removeItem: $.tzfdform.removeItem,
                    editItem: $.tzfdform.editItem
                };
                return this.fBuild = ffunct, this
            }, $.tzfdform.showSettings = {
                sizeList: new Array({
                    id: "small",
                    name: "Small"
                }, {
                    id: "medium",
                    name: "Medium"
                }, {
                    id: "large",
                    name: "Large"
                }),
                layoutList: new Array({
                    id: "one_column",
                    name: "One Column"
                }, {
                    id: "two_column",
                    name: "Two Column"
                }, {
                    id: "three_column",
                    name: "Three Column"
                }, {
                    id: "side_by_side",
                    name: "Side by Side"
                }),
                formlayoutList: new Array({
                    id: "top_aligned",
                    name: "Top Aligned"
                }, {
                    id: "left_aligned",
                    name: "Left Aligned"
                }, {
                    id: "right_aligned",
                    name: "Right Aligned"
                }, {
                    id: "center_aligned",
                    name: "Center Aligned"
                }),
                formTemplateDic: {},
                showFieldType: function(e) {
                    return "<label>Field Type: " + $.tzfdform.getNameByIdFromType(e) + "</label><br /><br />"
                },
                showTitle: function(e) {
                    return '<label>Field Label</label><textarea class="large" name="sTitle" id="sTitle">' + $.tzfdform.htmlEncode(e) + "</textarea>"
                },
                showShortLabel: function(e) {
                    return '<div><label>Short label (optional) [<a class="helptzfdform" text="The short label is used at title for the column when exporting the form data to CSV files.\n\nIf the short label is empty then, the field label will be used for the CSV file.">help?</a>] :</label><input class="large" name="sShortlabel" id="sShortlabel" value="' + $.tzfdform.htmlEncode(e) + '" /></div>'
                },
                showName: function(e) {
                    return '<div><label>Field name, tag for the message:</label><input readonly="readonly" class="large" name="sNametag" id="sNametag" value="&lt;%' + e + '%&gt;" /><input style="display:none" readonly="readonly" class="large" name="sName" id="sName" value="' + e + '" /></div>'
                },
                showPredefined: function(e, t) {
                    return '<div><label>Predefined Value</label><textarea class="large" name="sPredefined" id="sPredefined">' + e + '</textarea><br /><input type="checkbox" name="sPredefinedClick" id="sPredefinedClick" ' + (t ? "checked" : "") + ' value="1" > Use predefined value as placeholder.</div>'
                },
                showEqualTo: function(e, t) {
                    return '<div><label>Equal to [<a class="helptzfdform" text="Use this field to create password confirmation field or email confirmation fields.\n\nSpecify this setting ONLY into the confirmation field, not in the original field.">help?</a>]</label><br /><select class="equalTo" name="sEqualTo" id="sEqualTo" dvalue="' + e + '" dname="' + t + '"></select></div>'
                },
                showRequired: function(e) {
                    return '<div><input type="checkbox" name="sRequired" id="sRequired" ' + (e ? "checked" : "") + "><label>Required</label></div>"
                },
                showSize: function(e) {
                    for (var t = "", i = 0; i < this.sizeList.length; i++) t += '<option value="' + this.sizeList[i].id + '" ' + (this.sizeList[i].id == e ? "selected" : "") + ">" + this.sizeList[i].name + "</option>";
                    return '<label>Field Size</label><br /><select name="sSize" id="sSize">' + t + "</select>"
                },
                showLayout: function(e) {
                    for (var t = "", i = 0; i < this.layoutList.length; i++) t += '<option value="' + this.layoutList[i].id + '" ' + (this.layoutList[i].id == e ? "selected" : "") + ">" + this.layoutList[i].name + "</option>";
                    return '<label>Field Layout</label><br /><select name="sLayout" id="sLayout">' + t + "</select>"
                },
                showUserhelp: function(e, t) {
                    return '<div><label>Instructions for User</label><textarea class="large" name="sUserhelp" id="sUserhelp">' + e + '</textarea><br /><input type="checkbox" name="sUserhelpTooltip" id="sUserhelpTooltip" ' + (t ? "checked" : "") + ' value="1" > Show as floating tooltip.</div>'
                },
                showCsslayout: function(e) {
                    return '<label>Additional CSS Class</label><input class="large" name="sCsslayout" id="sCsslayout" value="' + $.tzfdform.htmlEncode(e) + '" />'
                }
            }, $.tzfdform.controls.ffields = function() {}, $.extend($.tzfdform.controls.ffields.prototype, {
                form_identifier: "",
                name: "",
                shortlabel: "",
                index: -1,
                ftype: "",
                userhelp: "",
                userhelpTooltip: !1,
                csslayout: "",
                init: function() {},
                editItemEvents: function(e) {
                    if (void 0 !== e && void 0 !== e.length)
                        for (var t = 0, i = e.length; t < i; t++) $(e[t].s).bind(e[t].e, {
                            obj: this,
                            i: e[t]
                        }, (function(e) {
                            var t = $(this).val();
                            void 0 !== e.data.i.f && (t = e.data.i.f($(this))), e.data.obj[e.data.i.l] = t, $.tzfdform.reloadItems({
                                field: e.data.obj
                            })
                        }));
                    if (void 0 !== e && void 0 !== e.length)
                        for (t = 0, i = e.length; t < i; t++) $(e[t].s).bind(e[t].e, {
                            obj: this,
                            i: e[t]
                        }, (function(e) {
                            var t = $(this).val();
                            void 0 !== e.data.i.f && (t = e.data.i.f($(this))), e.data.obj[e.data.i.l] = t, $.tzfdform.reloadItems({
                                field: e.data.obj
                            })
                        }));
                    $("#sTitle").bind("keyup", {
                        obj: this
                    }, (function(e) {
                        var t = $(this).val();
                        e.data.obj.title = t.replace(/\n/g, "<br />"), $.tzfdform.reloadItems()
                    })), $("#sShortlabel").bind("keyup", {
                        obj: this
                    }, (function(e) {
                        e.data.obj.shortlabel = $(this).val(), $.tzfdform.reloadItems()
                    })), $("#sPredefined").bind("keyup", {
                        obj: this
                    }, (function(e) {
                        e.data.obj.predefined = $(this).val(), $.tzfdform.reloadItems()
                    })), $("#sPredefinedClick").bind("click", {
                        obj: this
                    }, (function(e) {
                        e.data.obj.predefinedClick = $(this).is(":checked"), $.tzfdform.reloadItems()
                    })), $("#sRequired").bind("click", {
                        obj: this
                    }, (function(e) {
                        e.data.obj.required = $(this).is(":checked"), $.tzfdform.reloadItems()
                    })), $("#sUserhelp").bind("keyup", {
                        obj: this
                    }, (function(e) {
                        e.data.obj.userhelp = $(this).val(), $.tzfdform.reloadItems()
                    })), $("#sUserhelpTooltip").bind("click", {
                        obj: this
                    }, (function(e) {
                        e.data.obj.userhelpTooltip = $(this).is(":checked"), $.tzfdform.reloadItems()
                    })), $("#sCsslayout").bind("keyup", {
                        obj: this
                    }, (function(e) {
                        e.data.obj.csslayout = $(this).val(), $.tzfdform.reloadItems()
                    })), $(".helptzfdform").click((function() {
                        alert($(this).attr("text"))
                    }))
                },
                showSpecialData: function() {
                    return void 0 !== this.showSpecialDataInstance ? this.showSpecialDataInstance() : ""
                },
                showEqualTo: function() {
                    return void 0 !== this.equalTo ? $.tzfdform.showSettings.showEqualTo(this.equalTo, this.name) : ""
                },
                showPredefined: function() {
                    return void 0 !== this.predefined ? $.tzfdform.showSettings.showPredefined(this.predefined, this.predefinedClick) : ""
                },
                showRequired: function() {
                    return void 0 !== this.required ? $.tzfdform.showSettings.showRequired(this.required) : ""
                },
                showSize: function() {
                    return void 0 !== this.size ? $.tzfdform.showSettings.showSize(this.size) : ""
                },
                showLayout: function() {
                    return void 0 !== this.layout ? $.tzfdform.showSettings.showLayout(this.layout) : ""
                },
                showRange: function() {
                    return void 0 !== this.min ? this.showRangeIntance() : ""
                },
                showFormat: function() {
                    if (void 0 === this.dformat) return "";
                    try {
                        return this.showFormatIntance()
                    } catch (e) {
                        return ""
                    }
                },
                showChoice: function() {
                    return void 0 !== this.choices ? this.showChoiceIntance() : ""
                },
                showUserhelp: function() {
                    return $.tzfdform.showSettings.showUserhelp(this.userhelp, this.userhelpTooltip)
                },
                showCsslayout: function() {
                    return $.tzfdform.showSettings.showCsslayout(this.csslayout)
                },
                showAllSettings: function() {
                    return this.showFieldType() + this.showTitle() + this.showShortLabel() + this.showName() + this.showSize() + this.showLayout() + this.showFormat() + this.showRange() + this.showRequired() + this.showSpecialData() + this.showEqualTo() + this.showPredefined() + this.showChoice() + this.showUserhelp() + this.showCsslayout()
                },
                showFieldType: function() {
                    return $.tzfdform.showSettings.showFieldType(this.ftype)
                },
                showTitle: function() {
                    return $.tzfdform.showSettings.showTitle(this.title)
                },
                showName: function() {
                    return $.tzfdform.showSettings.showName(this.name)
                },
                showShortLabel: function() {
                    return $.tzfdform.showSettings.showShortLabel(this.shortlabel)
                },
                display: function() {
                    return "Not available yet"
                },
                show: function() {
                    return "Not available yet"
                }
            }), $.tzfdform.categoryList[2] = {
                title: "",
                description: ""
            }, $.tzfdform.categoryList[3] = {
                title: "Extended Form Controls (Commercial Versions)",
                description: ""
            }, $.tzfdform.categoryList[1] = {
                title: "Main Form Controls",
                description: ""
            }, $.tzfdform.categoryList[20] = {
                title: "Form Controls with Datasource Connection",
                description: ""
            }, $.tzfdform.categoryList[10] = {
                title: "Container Controls",
                description: ""
            },
            
            $.tzfdform.typeList.push({
                id: "femail",
                name: "Email",
                control_category: 1
            }), $.tzfdform.controls.femail = function() {}, $.extend($.tzfdform.controls.femail.prototype, $.tzfdform.controls.ffields.prototype, {
                title: "Email",
                ftype: "femail",
                predefined: "",
                predefinedClick: !1,
                required: !1,
                size: "medium",
                equalTo: "",
                display: function() {
                    return '<div class="fields ' + this.name + '" id="field' + this.form_identifier + "-" + this.index + '"><div class="arrow ui-icon ui-icon-play "></div><div title="Delete" class="remove ui-icon ui-icon-trash "></div><div title="Duplicate" class="copy ui-icon ui-icon-copy "></div><label>' + $.tzfdform.htmlEncode(this.title) + (this.required ? "*" : "") + '</label><div class="dfield"><input class="field disabled ' + this.size + '" type="text" value="' + $.tzfdform.htmlEncode(this.predefined) + '"/><span class="uh">' + $.tzfdform.htmlEncode(this.userhelp) + '</span></div><div class="clearer"></div></div>'
                },
                editItemEvents: function() {
                    $("#sSize").bind("change", {
                        obj: this
                    }, (function(e) {
                        e.data.obj.size = $(this).val(), $.tzfdform.reloadItems()
                    })), $("#sEqualTo").bind("change", {
                        obj: this
                    }, (function(e) {
                        e.data.obj.equalTo = $(this).val(), $.tzfdform.reloadItems()
                    }));
                    var e = this.fBuild.getItems();
                    $(".equalTo").each((function() {
                        for (var t = '<option value="" ' + ("" == $(this).attr("dvalue") ? "selected" : "") + "></option>", i = 0; i < e.length; i++) "ftext" != e[i].ftype && "femail" != e[i].ftype && "fpassword" != e[i].ftype || e[i].name == $(this).attr("dname") || (t += '<option value="' + e[i].name + '" ' + (e[i].name == $(this).attr("dvalue") ? "selected" : "") + ">" + e[i].title + "</option>");
                        $(this).html(t)
                    })), $.tzfdform.controls.ffields.prototype.editItemEvents.call(this)
                },
                showSpecialDataInstance: function() {
                    return ""
                }
            }), $.tzfdform.typeList.push({
                id: "ftext",
                name: "Single Line",
                control_category: 1
            }), $.tzfdform.controls.ftext = function() {}, $.extend($.tzfdform.controls.ftext.prototype, $.tzfdform.controls.ffields.prototype, {
                title: "Untitled",
                ftype: "ftext",
                predefined: "",
                predefinedClick: !1,
                required: !1,
                size: "medium",
                minlength: "",
                maxlength: "",
                equalTo: "",
                display: function() {
                    return '<div class="fields ' + this.name + '" id="field' + this.form_identifier + "-" + this.index + '"><div class="arrow ui-icon ui-icon-play "></div><div title="Delete" class="remove ui-icon ui-icon-trash "></div><div title="Duplicate" class="copy ui-icon ui-icon-copy "></div><label>' + $.tzfdform.htmlEncode(this.title) + (this.required ? "*" : "") + '</label><div class="dfield"><input class="field disabled ' + this.size + '" type="text" value="' + $.tzfdform.htmlEncode(this.predefined) + '"/><span class="uh">' + $.tzfdform.htmlEncode(this.userhelp) + '</span></div><div class="clearer"></div></div>'
                },
                editItemEvents: function() {
                    $("#sSize").bind("change", {
                        obj: this
                    }, (function(e) {
                        e.data.obj.size = $(this).val(), $.tzfdform.reloadItems()
                    })), $("#sMinlength").bind("change", {
                        obj: this
                    }, (function(e) {
                        e.data.obj.minlength = $(this).val(), $.tzfdform.reloadItems()
                    })), $("#sMaxlength").bind("change", {
                        obj: this
                    }, (function(e) {
                        e.data.obj.maxlength = $(this).val(), $.tzfdform.reloadItems()
                    })), $("#sEqualTo").bind("change", {
                        obj: this
                    }, (function(e) {
                        e.data.obj.equalTo = $(this).val(), $.tzfdform.reloadItems()
                    }));
                    var e = this.fBuild.getItems();
                    $(".equalTo").each((function() {
                        for (var t = '<option value="" ' + ("" == $(this).attr("dvalue") ? "selected" : "") + "></option>", i = 0; i < e.length; i++) "ftext" != e[i].ftype && "femail" != e[i].ftype && "fpassword" != e[i].ftype || e[i].name == $(this).attr("dname") || (t += '<option value="' + e[i].name + '" ' + (e[i].name == $(this).attr("dvalue") ? "selected" : "") + ">" + e[i].title + "</option>");
                        $(this).html(t)
                    })), $.tzfdform.controls.ffields.prototype.editItemEvents.call(this)
                },
                showSpecialDataInstance: function() {
                    return '<div class="column"><label>Min length/characters</label><br /><input name="sMinlength" id="sMinlength" value="' + $.tzfdform.htmlEncode(this.minlength) + '"></div><div class="column"><label>Max length/characters</label><br /><input name="sMaxlength" id="sMaxlength" value="' + $.tzfdform.htmlEncode(this.maxlength) + '"></div><div class="clearer"></div>'
                }
            }), $.tzfdform.typeList.push({
                id: "fapp",
                name: "Appointment",
                control_category: 1
            }), $.tzfdform.controls.fapp = function() {
                this.init()
            }, $.extend($.tzfdform.controls.fapp.prototype, $.tzfdform.controls.ffields.prototype, {
                title: "Appointment",
                ftype: "fapp",
                services: new Array({
                    name: "Sample Service",
                    price: 24.99,
                    capacity: 1,
                    duration: 60,
                    pb: 0,
                    pa: 0,
                    ohindex: 0
                }),
                openhours: new Array,
                allOH: new Array({
                    name: "Default",
                    openhours: new Array({
                        type: "all",
                        d: "",
                        h1: 8,
                        m1: 0,
                        h2: 17,
                        m2: 0
                    })
                }),
                dateFormat: "mm/dd/yy",
                showDropdown: !1,
                showTotalCost: !1,
                showTotalCostFormat: "$ {0}",
                showEndTime: !1,
                showQuantity: !1,
                usedSlotsCheckbox: !1,
                avoidOverlaping: !0,
                emptySelectCheckbox: !1,
                emptySelect: "-- Please select service --",
                dropdownRange: "-10:+10",
                working_dates: [!0, !0, !0, !0, !0, !0, !0],
                numberOfMonths: 1,
                maxNumberOfApp: 0,
                firstDay: 0,
                minDate: "0",
                maxDate: "",
                defaultDate: "",
                invalidDates: "",
                tmpinvalidDates: [],
                required: !0,
                bSlotsCheckbox: !0,
                bSlots: 30,
                militaryTime: 1,
                autonum: 0,
                autonumStaff: 0,
                quantityMin: 1,
                display: function() {
                    return '<div class="fields ' + this.name + ' fapp" id="field' + this.form_identifier + "-" + this.index + '"><div class="arrow ui-icon ui-icon-play "></div><div title="Duplicate" class="copy ui-icon ui-icon-copy "></div><div title="Delete" class="remove ui-icon ui-icon-trash "></div><label>' + $.tzfdform.htmlEncode(this.title) + (this.required ? "*" : "") + '</label><div class="dfield"><div class="fieldCalendarService' + this.name + '"></div><div class="fieldCalendar' + this.name + '"></div><div class="slotsCalendar' + this.name + '"></div><span class="uh">' + $.tzfdform.htmlEncode(this.userhelp) + '</span></div><div class="clearer"></div></div>'
                },
                msort: function(e) {
                    for (var t = 0; t < e.length; t++)
                        for (var i = t + 1; i < e.length; i++)
                            if (e[t].type > e[i].type || e[t].type == e[i].type && ("special" == e[t].type || "specialrange" == e[t].type) && e[t].d > e[i].d || e[t].type == e[i].type && "special" != e[t].type && "specialrange" != e[t].type && 60 * e[t].h1 + e[t].m1 > 60 * e[i].h1 + e[i].m1) {
                                var a = e[t];
                                e[t] = e[i], e[i] = a
                            } return e
                },
                rangeFormat: function(e, t) {
                    if ("" != e) {
                        var i = e.split(":");
                        return 2 == i.length ? $.datepicker.formatDate(t, $.datepicker.parseDate("yy-mm-dd", i[0])) + ":" + $.datepicker.formatDate(t, $.datepicker.parseDate("yy-mm-dd", i[1])) : $.datepicker.formatDate(t, $.datepicker.parseDate("yy-mm-dd", i[0]))
                    }
                    return e
                },
                normalizeSelectIndex: function(e) {
                    return this.emptySelectCheckbox && e--, e
                },
                showopenhourAll: function() {
                    for (var e = "", t = ($("#field" + this.form_identifier + "-" + this.index + " .fieldCalendar" + this.name), this), i = 0; i < t.allOH.length; i++) e += '<div class="openhoursdiv" i="' + i + '">', i > 0 && (e += '<div><label>Open hours:  <input type="text" class="nameopenhours" i="' + i + '" value="' + t.allOH[i].name + '"></label> <input type="button" i="' + i + '" class="deleteopenhours" value="Delete"></div>'), e += t.showOpenHours(t.allOH[i].openhours, t.dateFormat), e += "<hr /></div>";
                    $("#openHoursSection").html(e), $(".openhours_special").datepicker("destroy").datepicker({
                        dateFormat: t.dateFormat,
                        onSelect: function(e, i) {
                            var a = 1 * i.input.parents(".openhoursdiv").attr("i");
                            t.allOH[a].openhours[i.input.attr("i")].d = $.datepicker.formatDate("yy-mm-dd", $.datepicker.parseDate(t.dateFormat, e)), $.tzfdform.reloadItems({
                                field: t
                            })
                        }
                    }), $(".openhours_specialrange").datepicker("destroy").datepicker({
                        dateFormat: t.dateFormat,
                        beforeShowDay: function(e) {
                            var i = 1 * $(this).parents(".openhoursdiv").attr("i");
                            if ("" != t.allOH[i].openhours[$(this).attr("i")].d) {
                                var a = t.allOH[i].openhours[$(this).attr("i")].d.split(":"),
                                    s = $.datepicker.parseDate("yy-mm-dd", a[0]),
                                    o = $.datepicker.parseDate("yy-mm-dd", a[0]);
                                if (2 == a.length) o = $.datepicker.parseDate("yy-mm-dd", a[1])
                            }
                            return s && o ? [!0, e >= s && e <= o ? "highlight" : ""] : [!0, ""]
                        },
                        onSelect: function(e, i) {
                            $(this).data("datepicker").inline = !0;
                            var a = $(this).datepicker("getDate"),
                                s = 1 * $(this).parents(".openhoursdiv").attr("i"),
                                o = t.allOH[s].openhours[$(this).attr("i")] || {};
                            if ("" != t.allOH[s].openhours[$(this).attr("i")].d) {
                                var l = t.allOH[s].openhours[$(this).attr("i")].d.split(":");
                                2 == l.length ? (o.startDate = $.datepicker.parseDate("yy-mm-dd", l[0]), o.endDate = $.datepicker.parseDate("yy-mm-dd", l[1])) : o.startDate = $.datepicker.parseDate("yy-mm-dd", l[0])
                            }
                            if (!o.startDate || o.startDate && o.endDate) o.startDate = a, o.endDate = null;
                            else if (o.endDate = a, o.startDate > o.endDate) {
                                var n = new Date(o.startDate);
                                o.startDate = new Date(o.endDate), o.endDate = new Date(n)
                            }
                            t.allOH[s].openhours[$(this).attr("i")].d = $.datepicker.formatDate("yy-mm-dd", o.startDate) + (o.endDate ? ":" + $.datepicker.formatDate("yy-mm-dd", o.endDate) : ""), $(this).val(t.rangeFormat(t.allOH[s].openhours[$(this).attr("i")].d, t.dateFormat)), $.tzfdform.reloadItems({
                                field: t
                            }), $(this).datepicker("show"), o.startDate && o.endDate && ($(this).data("datepicker").inline = !1)
                        }
                    }), $(".service_openhours").each((function() {
                        for (var e = "", i = 0; i < t.allOH.length; i++) e += '<option  value="' + i + '">' + t.allOH[i].name + "</option>";
                        $(this).html(e), $(this).val(t.services[1 * $(this).attr("i")].ohindex), t.allOH.length > 1 ? $(this).parent().css("display", "block") : $(this).parent().css("display", "none")
                    }))
                },
                showOpenHours: function(e, t) {
                    var i = "",
                        a = $.datepicker.regional[""].dayNames,
                        s = this;
                    i += '<div><div class="labelT">Day(s)</div><div class="labelF">From</div><div class="labelF">To</div><div class="clearer"></div></div>';
                    for (var o = 0; o < e.length; o++) {
                        i += '<div class="choicesEdit">', i += '<select class="openhours_type" i="' + o + '" "><option value="all" ' + ("all" == e[o].type ? "selected" : "") + ">All days</option>";
                        for (var l = 0; l < 7; l++) i += '<option value="d' + l + '" ' + (e[o].type == "d" + l ? "selected" : "") + ">" + a[l] + "</option>";
                        i += '<option value="special" ' + ("special" == e[o].type ? "selected" : "") + ">Only ...</option>", i += '<option value="specialrange" ' + ("specialrange" == e[o].type ? "selected" : "") + ">From ... to ...</option></select>", i += '<div class="specialcover"><input style="display:' + ("special" == e[o].type ? "inline" : "none") + '" class="openhours_special" i="' + o + '" type="text"  value="' + ("special" == e[o].type ? $.tzfdform.htmlEncode($.datepicker.formatDate(t, $.datepicker.parseDate("yy-mm-dd", e[o].d))) : "") + '"/>', i += '<input style="display:' + ("specialrange" == e[o].type ? "inline" : "none") + '" class="openhours_specialrange" i="' + o + '" type="text"  value="' + ("specialrange" == e[o].type ? $.tzfdform.htmlEncode(s.rangeFormat(e[o].d, t)) : "") + '"/>', i += '</div><select class="openhours_from" i="' + o + '">';
                        for (var n = 0; n < 24; n++)
                            for (var r = 0; r < 60; r += 5) i += '<option h="' + n + '" m="' + r + '" value="' + (n < 10 ? "0" : "") + n + ":" + (r < 10 ? "0" : "") + r + '" ' + (e[o].h1 == n && e[o].m1 == r ? "selected" : "") + ">" + s.formattime(60 * n + r) + "</option>";
                        i += "</select>", i += '<select class="openhours_to" i="' + o + '">';
                        for (n = 0; n < 24; n++)
                            for (r = 0; r < 60; r += 5) i += '<option h="' + n + '" m="' + r + '" value="' + (n < 10 ? "0" : "") + n + ":" + (r < 10 ? "0" : "") + r + '" ' + (e[o].h2 == n && e[o].m2 == r ? "selected" : "") + ">" + s.formattime(60 * n + r) + "</option>";
                        i += '<option h="0" m="0" value="00:00">00:00</option>', i += "</select>", i += '<a class="choice_add ui-icon ui-icon-circle-plus" i="' + o + '" title="Add another choice."></a><a class="choice_remove ui-icon ui-icon-circle-minus" i="' + o + '" title="Delete this choice."></a></div>'
                    }
                    return i
                },
                showSlots: function() {
                    function e(e, t, i) {
                        for (var a = 0; a < i.length; a++)
                            if (60 * e + t >= 60 * i[a].h1 + i[a].m1 && 60 * e + t <= 60 * i[a].h2 + i[a].m2) return "sopen";
                        return ""
                    }
                    for (var t = "", i = 0; i < 24; i++) {
                        t += "<div><div>" + i + "</div>";
                        for (var a = 0; a < 60; a += 5) t += '<div id="d' + i + "-" + a + '" class="slot ' + e(i, a, this.slots) + '" ></div>';
                        t += "</div>"
                    }
                    return t + this.slots.length
                },
                editItemEvents: function() {
                    var e = this;
                    e.showopenhourAll();
                    var t = [{
                        s: "#sDateFormat",
                        e: "change",
                        l: "dateFormat"
                    }, {
                        s: "#sNumberOfMonths",
                        e: "change",
                        l: "numberOfMonths"
                    }, {
                        s: "#sMaxNumberOfApp",
                        e: "change",
                        l: "maxNumberOfApp"
                    }, {
                        s: "#sFirstDay",
                        e: "change",
                        l: "firstDay"
                    }, {
                        s: "#sMilitaryTime",
                        e: "change",
                        l: "militaryTime"
                    }, {
                        s: "#sMinDate",
                        e: "change",
                        l: "minDate"
                    }, {
                        s: "#sMaxDate",
                        e: "change",
                        l: "maxDate"
                    }, {
                        s: "#sInvalidDates",
                        e: "keyup",
                        l: "invalidDates"
                    }, {
                        s: "#sDefaultDate",
                        e: "change",
                        l: "defaultDate"
                    }, {
                        s: "#sDropdownRange",
                        e: "keyup",
                        l: "dropdownRange"
                    }, {
                        s: "#sShowTotalCostFormat",
                        e: "keyup",
                        l: "showTotalCostFormat"
                    }, {
                        s: "#sQuantityMin",
                        e: "change",
                        l: "quantityMin"
                    }, {
                        s: "#sEmptySelectCheckbox",
                        e: "click",
                        l: "emptySelectCheckbox",
                        f: function(e) {
                            var t = e.is(":checked");
                            return $("#sEmptySelectDiv")[t ? "show" : "hide"](), t
                        }
                    }, {
                        s: "#sBSlotsCheckbox",
                        e: "click",
                        l: "bSlotsCheckbox",
                        f: function(e) {
                            var t = e.is(":checked");
                            return $("#sBSlotsDiv")[t ? "hide" : "show"](), t
                        }
                    }, {
                        s: "#sEmptySelect",
                        e: "keyup",
                        l: "emptySelect"
                    }, {
                        s: "#sBSlots",
                        e: "change",
                        l: "bSlots"
                    }, {
                        s: "#sShowEndTime",
                        e: "click",
                        l: "showEndTime",
                        f: function(e) {
                            return e.is(":checked")
                        }
                    }, {
                        s: "#sShowQuantity",
                        e: "click",
                        l: "showQuantity",
                        f: function(e) {
                            var t = e.is(":checked");
                            return $("#divQuantityMin")[t ? "show" : "hide"](), t
                        }
                    }, {
                        s: "#sUsedSlotsCheckbox",
                        e: "click",
                        l: "usedSlotsCheckbox",
                        f: function(e) {
                            return e.is(":checked")
                        }
                    }, {
                        s: "#sAvoidOverlaping",
                        e: "click",
                        l: "avoidOverlaping",
                        f: function(e) {
                            return e.is(":checked")
                        }
                    }, {
                        s: "#sShowTotalCost",
                        e: "click",
                        l: "showTotalCost",
                        f: function(e) {
                            var t = e.is(":checked");
                            return $("#divTotalCostFormat")[t ? "show" : "hide"](), t
                        }
                    }, {
                        s: "#sShowDropdown",
                        e: "click",
                        l: "showDropdown",
                        f: function(e) {
                            var t = e.is(":checked");
                            return $("#divdropdownRange")[t ? "show" : "hide"](), t
                        }
                    }];
                    $(".working_dates input").bind("click", {
                        obj: this
                    }, (function(e) {
                        e.data.obj.working_dates[$(this).val()] = $(this).is(":checked"), $.tzfdform.reloadItems({
                            field: e.data.obj
                        })
                    })), $(".service_name").bind("keyup", {
                        obj: this
                    }, (function(e) {
                        e.data.obj.services[$(this).attr("i")].name = $(this).val(), $.tzfdform.reloadItems({
                            field: e.data.obj
                        })
                    })), $(".service_price").bind("keyup", {
                        obj: this
                    }, (function(e) {
                        e.data.obj.services[$(this).attr("i")].price = Number($(this).val().replace(/[^0-9\.]+/g, "")), $.tzfdform.reloadItems({
                            field: e.data.obj
                        })
                    })), $(".service_capacity").bind("keyup", {
                        obj: this
                    }, (function(e) {
                        e.data.obj.services[$(this).attr("i")].capacity = $(this).val(), $.tzfdform.reloadItems({
                            field: e.data.obj
                        })
                    })), $(".service_duration").bind("change", {
                        obj: this
                    }, (function(e) {
                        e.data.obj.services[$(this).attr("i")].duration = $(this).val(), $.tzfdform.reloadItems({
                            field: e.data.obj
                        })
                    })), $(".service_paddingbefore").bind("change", {
                        obj: this
                    }, (function(e) {
                        e.data.obj.services[$(this).attr("i")].pb = parseInt($(this).val()), $.tzfdform.reloadItems({
                            field: e.data.obj
                        })
                    })), $(".service_paddingafter").bind("change", {
                        obj: this
                    }, (function(e) {
                        e.data.obj.services[$(this).attr("i")].pa = parseInt($(this).val()), $.tzfdform.reloadItems({
                            field: e.data.obj
                        })
                    })), $(document).off("change", ".service_openhours").on("change", ".service_openhours", {
                        obj: this
                    }, (function(e) {
                        e.data.obj.services[$(this).attr("i")].ohindex = parseInt($(this).val()), $.tzfdform.reloadItems({
                            field: e.data.obj
                        })
                    })), $(".services .choice_up").bind("click", {
                        obj: this
                    }, (function(e) {
                        var t = 1 * $(this).attr("i");
                        0 != t && e.data.obj.services.splice(t - 1, 0, e.data.obj.services.splice(t, 1)[0]), $.tzfdform.editItem(e.data.obj.index), $.tzfdform.reloadItems({
                            field: e.data.obj
                        })
                    })), $(".services .choice_down").bind("click", {
                        obj: this
                    }, (function(e) {
                        var t = 1 * $(this).attr("i");
                        t != 1 * $(this).attr("n") && e.data.obj.services.splice(t, 0, e.data.obj.services.splice(t + 1, 1)[0]), $.tzfdform.editItem(e.data.obj.index), $.tzfdform.reloadItems({
                            field: e.data.obj
                        })
                    })), $(".services .choice_add").bind("click", {
                        obj: this
                    }, (function(e) {
                        e.data.obj.autonum++, e.data.obj.services.splice(1 * $(this).attr("i") + 1, 0, {
                            name: "Sample Service",
                            price: 24.99,
                            capacity: 1,
                            duration: 60,
                            pb: 0,
                            pa: 0,
                            ohindex: 0,
                            idx: e.data.obj.autonum
                        }), $.tzfdform.editItem(e.data.obj.index), $.tzfdform.reloadItems({
                            field: e.data.obj
                        })
                    })), $(".services .choice_remove").bind("click", {
                        obj: this
                    }, (function(e) {
                        e.data.obj.services.length > 1 && e.data.obj.services.splice(1 * $(this).attr("i"), 1), $.tzfdform.editItem(e.data.obj.index), $.tzfdform.reloadItems({
                            field: e.data.obj
                        })
                    })), $(".openhours").off("change", ".openhours_type").on("change", ".openhours_type", {
                        obj: this
                    }, (function(e) {
                        var t = 1 * $(this).parents(".openhoursdiv").attr("i");
                        e.data.obj.allOH[t].openhours[$(this).attr("i")].type = $(this).val(), $(this).parents(".choicesEdit").find(".openhours_special").css("display", "none"), $(this).parents(".choicesEdit").find(".openhours_specialrange").css("display", "none"), "special" == $(this).val() ? $(this).parents(".choicesEdit").find(".openhours_special").css("display", "inline") : "specialrange" == $(this).val() && $(this).parents(".choicesEdit").find(".openhours_specialrange").css("display", "inline"), $.tzfdform.reloadItems({
                            field: e.data.obj
                        })
                    })), $(".openhours").off("change", ".openhours_from").on("change", ".openhours_from", {
                        obj: this
                    }, (function(e) {
                        var t = 1 * $(this).parents(".openhoursdiv").attr("i");
                        e.data.obj.allOH[t].openhours[$(this).attr("i")].h1 = $(this).find(":selected").attr("h"), e.data.obj.allOH[t].openhours[$(this).attr("i")].m1 = $(this).find(":selected").attr("m"), $.tzfdform.reloadItems({
                            field: e.data.obj
                        })
                    })), $(".openhours").off("change", ".openhours_to").on("change", ".openhours_to", {
                        obj: this
                    }, (function(e) {
                        var t = 1 * $(this).parents(".openhoursdiv").attr("i");
                        e.data.obj.allOH[t].openhours[$(this).attr("i")].h2 = $(this).find(":selected").attr("h"), e.data.obj.allOH[t].openhours[$(this).attr("i")].m2 = $(this).find(":selected").attr("m"), $.tzfdform.reloadItems({
                            field: e.data.obj
                        })
                    })), $(".openhours").off("click", ".choice_add").on("click", ".choice_add", {
                        obj: this
                    }, (function(t) {
                        var i = 1 * $(this).parents(".openhoursdiv").attr("i");
                        t.data.obj.allOH[i].openhours.splice(1 * $(this).attr("i") + 1, 0, {
                            type: "all",
                            d: "",
                            h1: 8,
                            m1: 0,
                            h2: 17,
                            m2: 0
                        }), t.data.obj.allOH[i].openhours = e.msort(t.data.obj.allOH[i].openhours), $.tzfdform.editItem(t.data.obj.index), $.tzfdform.reloadItems({
                            field: t.data.obj
                        })
                    })), $(".openhours").off("click", ".choice_remove").on("click", ".choice_remove", {
                        obj: this
                    }, (function(e) {
                        var t = 1 * $(this).parents(".openhoursdiv").attr("i");
                        e.data.obj.allOH[t].openhours.length > 1 && e.data.obj.allOH[t].openhours.splice(1 * $(this).attr("i"), 1), $.tzfdform.editItem(e.data.obj.index), $.tzfdform.reloadItems({
                            field: e.data.obj
                        })
                    })), $(".openhours").off("click", "input.addOpenHours").on("click", "input.addOpenHours", {
                        obj: this
                    }, (function(t) {
                        t.data.obj.allOH[t.data.obj.allOH.length] = {
                            name: "Special",
                            openhours: new Array({
                                type: "all",
                                d: "",
                                h1: 8,
                                m1: 0,
                                h2: 17,
                                m2: 0
                            })
                        }, e.showopenhourAll(), $.tzfdform.reloadItems({
                            field: t.data.obj
                        })
                    })), $(".openhours").off("click", "input.deleteopenhours").on("click", "input.deleteopenhours", {
                        obj: this
                    }, (function(t) {
                        for (var i = 1 * $(this).attr("i"), a = 0; a < t.data.obj.services.length; a++) t.data.obj.services[a].ohindex > i ? t.data.obj.services[a].ohindex-- : t.data.obj.services[a].ohindex == i && (t.data.obj.services[a].ohindex = 0);
                        t.data.obj.allOH.splice(i, 1), e.showopenhourAll(), $.tzfdform.reloadItems({
                            field: t.data.obj
                        })
                    })), $(".openhours").off("keyup", "input.nameopenhours").on("keyup", "input.nameopenhours", {
                        obj: this
                    }, (function(e) {
                        e.data.obj.allOH[1 * $(this).attr("i")].name = $(this).val(), $.tzfdform.reloadItems({
                            field: e.data.obj
                        })
                    })), $.tzfdform.controls.ffields.prototype.editItemEvents.call(this, t)
                },
                formattime: function(e) {
                    e < 0 && (e += 1440), e %= 1440;
                    var t = Math.floor(e / 60),
                        i = e % 60,
                        a = "";
                    return 0 == this.militaryTime && (t > 12 ? (t -= 12, a = " PM") : 12 == t ? a = " PM" : (0 == t && (t = 12), a = " AM")), (t < 10 ? "0" : "") + t + ":" + (i < 10 ? "0" : "") + i + a
                },
                after_show: function() {
                    var e = this,
                        t = $("#field" + e.form_identifier + "-" + e.index + " .fieldCalendar" + e.name),
                        i = $("#field" + e.form_identifier + "-" + e.index + " .fieldCalendarService" + e.name),
                        a = "";
                    if (e.openhours.length > 0) {
                        if (!e.openhours[0].name) {
                            var s = {
                                name: "Default",
                                openhours: e.openhours.slice(0)
                            };
                            e.openhours = new Array, e.openhours[0] = s
                        }
                        e.allOH = new Array, e.allOH = e.openhours.slice(0), e.openhours = new Array
                    }
                    for (var o = 0; o < e.services.length; o++) e.services[o].ohindex = e.services[o].ohindex || 0;
                    if (0 == e.autonum)
                        for (o = 0; o < e.services.length; o++) e.autonum++, e.services[o].idx = e.autonum;

                    function l(t) {
                        if (!e.emptySelectCheckbox || e.emptySelectCheckbox && $(".fieldCalendarService" + e.name + " select option:selected").index() > 0) {
                            for (var i = new Array, a = $("#field" + e.form_identifier + "-" + e.index + " .slotsCalendar" + e.name), s = e.services[e.normalizeSelectIndex($(".fieldCalendarService" + e.name + " select option:selected").index())].ohindex, o = 0; o < e.allOH[s].openhours.length; o++)
                                if ("special" == e.allOH[s].openhours[o].type) i[e.allOH[s].openhours[o].d] = i[e.allOH[s].openhours[o].d] || [], i[e.allOH[s].openhours[o].d][i[e.allOH[s].openhours[o].d].length] = e.allOH[s].openhours[o];
                                else if ("specialrange" == e.allOH[s].openhours[o].type) {
                                if ("" != e.allOH[s].openhours[o].d) {
                                    if (2 == (c = e.allOH[s].openhours[o].d.split(":")).length)
                                        for (f = $.datepicker.parseDate("yy-mm-dd", c[0]), p = $.datepicker.parseDate("yy-mm-dd", c[1]), f.setDate(f.getDate() + 1); f <= p;) {
                                            i[r = $.datepicker.formatDate("yy-mm-dd", f)] = i[r] || [], i[r][i[r].length] = e.allOH[s].openhours[o], f.setDate(f.getDate() + 1)
                                        }
                                    i[c[0]] = i[c[0]] || [], i[c[0]][i[c[0]].length] = e.allOH[s].openhours[o]
                                }
                            } else i[e.allOH[s].openhours[o].type] = i[e.allOH[s].openhours[o].type] || [], i[e.allOH[s].openhours[o].type][i[e.allOH[s].openhours[o].type].length] = e.allOH[s].openhours[o];
                            var l = 1 * $(".fieldCalendarService" + e.name + " select option:selected").val(),
                                n = l;
                            e.bSlotsCheckbox || (n = 1 * e.bSlots);
                            var r, d, c = "",
                                u = e.normalizeSelectIndex($(".fieldCalendarService" + e.name + " select option:selected").index()),
                                h = 1 * e.services[u].pa || 0,
                                m = 1 * e.services[u].pb || 0;
                            i[t] ? c = b(i[t], l, n, h, m) : i["d" + $.datepicker.parseDate("yy-mm-dd", t).getDay()] ? c = b(i["d" + $.datepicker.parseDate("yy-mm-dd", t).getDay()], l, n, h, m) : i.all && (c = b(i.all, l, n, h, m)), a.html('<div class="slots">' + $.datepicker.formatDate(e.dateFormat, $.datepicker.parseDate("yy-mm-dd", t)) + "<br />" + c + "</div>")
                        } else $("#field" + e.form_identifier + "-" + e.index + " .slotsCalendar" + e.name).html("");

                        function b(t, i, a, s, o) {
                            var l = "",
                                n = new Array;
                            e.bSlotsCheckbox && (a += s + o);
                            for (var c = 0; c < t.length; c++)
                                for (r = 60 * t[c].h1 + 1 * t[c].m1, d = 60 * t[c].h2 + 1 * t[c].m2, (r += o) >= d && (d += 1440); r + i + s <= d && r < 1440;) - 1 == $.inArray(r, n) && r >= 0 && (n[n.length] = r), r += a;
                            n.sort((function(e, t) {
                                return e - t
                            }));
                            for (c = 0; c < n.length; c++) l += "<div>" + e.formattime(n[c]) + (e.showEndTime ? "-" + e.formattime(n[c] + i) : "") + "</div>";
                            return l
                        }
                    }
                    for (o = 0; o < e.services.length; o++) a += '<option value="' + e.services[o].duration + '">' + e.services[o].name + "</option>";
                    if (e.emptySelectCheckbox && (a = '<option value="">' + e.emptySelect + "</option>" + a), i.html("<select>" + a + "</select>"), e.emptySelectCheckbox && i.find("select").prop("selectedIndex", 1), $(".fieldCalendarService" + e.name + " select").bind("change", (function() {
                            t.datepicker("getDate") && l($.datepicker.formatDate("yy-mm-dd", t.datepicker("getDate")))
                        })), this.tmpinvalidDates = this.invalidDates, this.tmpinvalidDates = this.tmpinvalidDates.replace(/\s+/g, ""), !/^\s*$/.test(this.tmpinvalidDates)) {
                        var n = new RegExp(/^\d{1,2}\/\d{1,2}\/\d{4}$/),
                            r = 0,
                            d = this.tmpinvalidDates.split(",");
                        this.tmpinvalidDates = [];
                        o = 0;
                        for (var c = d.length; o < c; o++) {
                            var u = d[o].split("-");
                            if (2 == u.length && null != u[0].match(n) && null != u[1].match(n))
                                for (var f = new Date(u[0]), p = new Date(u[1]); f <= p;) {
                                    this.tmpinvalidDates[r] = f;
                                    var h = new Date(f.valueOf());
                                    h.setDate(h.getDate() + 1), f = h, r++
                                } else
                                    for (var m = 0, b = u.length; m < b; m++) null != u[m].match(n) && (this.tmpinvalidDates[r] = new Date(u[m]), r++)
                        }
                    }
                    if (e.special_days = new Array, !e.emptySelectCheckbox || e.emptySelectCheckbox && $(".fieldCalendarService" + e.name + " select option:selected").index() > 0) {
                        var v = e.services[e.normalizeSelectIndex($(".fieldCalendarService" + e.name + " select option:selected").index())].ohindex;
                        for (o = 0; o < e.allOH[v].openhours.length; o++)
                            if ("special" == e.allOH[v].openhours[o].type) e.special_days[e.special_days.length] = e.allOH[v].openhours[o].d;
                            else if ("specialrange" == e.allOH[v].openhours[o].type && "" != e.allOH[v].openhours[o].d) {
                            if (2 == (a = e.allOH[v].openhours[o].d.split(":")).length)
                                for (f = $.datepicker.parseDate("yy-mm-dd", a[0]), p = $.datepicker.parseDate("yy-mm-dd", a[1]), f.setDate(f.getDate() + 1); f <= p;) {
                                    var y = $.datepicker.formatDate("yy-mm-dd", f);
                                    e.special_days[e.special_days.length] = y, f.setDate(f.getDate() + 1)
                                }
                            e.special_days[e.special_days.length] = a[0]
                        }
                    }
                    $("#date_format").length > 0 && (e.dateFormat = $("#date_format").val());
                    var g = 0;
                    if (e.minDateTmp = e.minDate, "" !== e.minDate && -1 != e.minDate.indexOf("h"))
                        if (-1 != e.minDate.indexOf(" ")) {
                            var D = e.minDate.split(" "),
                                x = !1;
                            for (o = 0; o < D.length && !x; o++) - 1 != D[o].indexOf("h") && (x = !0, g = parseInt(D[o].replace("h", "")), e.minDateTmp = e.minDate.replace(D[o], ""))
                        } else g = parseInt(e.minDate.replace("h", "")), e.minDateTmp = 0;
                    if (t.datepicker({
                            numberOfMonths: parseInt(e.numberOfMonths),
                            firstDay: parseInt(e.firstDay),
                            minDate: e.minDateTmp,
                            maxDate: e.maxDate,
                            dateFormat: e.dateFormat,
                            defaultDate: e.defaultDate,
                            changeMonth: e.showDropdown,
                            changeYear: e.showDropdown,
                            yearRange: e.showDropdown ? e.dropdownRange : "",
                            onSelect: function(t, i) {
                                l($.datepicker.formatDate("yy-mm-dd", $.datepicker.parseDate(e.dateFormat, t)))
                            },
                            beforeShowDay: function(t) {
                                var i = $.datepicker.formatDate("yy-mm-dd", t),
                                    a = new Array(i);
                                0 == e.working_dates[t.getDay()] && -1 == $.inArray(i, e.special_days) && a.push("nonworking");
                                for (var s = 0, o = e.tmpinvalidDates.length; s < o; s++) t.getTime() === e.tmpinvalidDates[s].getTime() && a.push("nonworking invalidDate");
                                return [!0, a.join(" ")]
                            }
                        }), "" !== e.minDateTmp) {
                        e.getMinDate = t.datepicker("getDate");
                        var w = new Date,
                            k = 1;
                        try {
                            $.datepicker.parseDate(e.dateFormat, e.minDateTmp), k = 0
                        } catch (t) {}
                        e.getMinDate = new Date(e.getMinDate.getTime() + k * w.getHours() * 60 * 60 * 1e3 + k * w.getMinutes() * 60 * 1e3 + 60 * g * 60 * 1e3), t.datepicker("option", "minDate", e.getMinDate), t.datepicker("setDate", e.getMinDate)
                    }
                    e.tmpinvalidDatestime = new Array;
                    for (o = 0; o < e.tmpinvalidDates.length; o++) e.tmpinvalidDatestime[o] = e.tmpinvalidDates[o].getTime();
                    var S, I, j = 0;
                    for (o = 0; o < e.working_dates.length; o++) j += e.working_dates[o];
                    if (j > 0) {
                        for (var T = t.datepicker("getDate"); I = void 0, I = (S = T).getTime(), $.inArray(I, e.tmpinvalidDatestime) > -1 || 0 == e.working_dates[S.getDay()];) T.setDate(T.getDate() + 1);
                        t.datepicker("setDate", T), l($.datepicker.formatDate("yy-mm-dd", T))
                    }
                },
                showSpecialDataInstance: function() {
                    var e = "",
                        t = ($("#field" + this.form_identifier + "-" + this.index + " .fieldCalendar" + this.name), $.datepicker.regional[""].dayNames);
                    e += '<div class="choicesSet services"><label>Services [<a class="helptzfdform" text="Add the services offered:\n\n.Name: The service name\n\n.Price: Price number only, without currency symbol or code\n\n.Capacity: For example the number of persons can book/attend the same service at the same time, default is 1\n\n.Duration: The service duration, example 30 mins\n\n.Padding: The padding time is a period before and/or after the appointment not used for other bookings. It can be used for prep for next appointment, travel, clean-up or a snack/bathroom break.">help?</a>]</label>';
                    for (var i = 0; i < this.services.length; i++) {
                        e += '<div class="choicesEdit" style="background:#ddd">', e += '<div><div class="labelN">Name</div><div class="labelP">Price</div><div class="labelC">Capacity</div><div class="clearer"></div></div>', e += '<input class="service_name" i="' + i + '" type="text" name="sService' + this.name + '" id="sService' + this.name + '" value="' + $.tzfdform.htmlEncode(this.services[i].name) + '"/><input class="service_price" i="' + i + '" type="text" name="sService' + this.name + "P" + i + '" id="sService' + this.name + "P" + i + '" value="' + $.tzfdform.htmlEncode(this.services[i].price) + '"/>', e += '<input class="service_capacity" i="' + i + '" type="text" name="sService' + this.name + "C" + i + '" id="sService' + this.name + "C" + i + '" value="' + $.tzfdform.htmlEncode(parseInt(this.services[i].capacity) > 0 ? this.services[i].capacity : "1") + '"/>', e += '<div><div class="labeltzfd">Duration</div><div >Padding time before and after</div><div class="clearer"></div></div>', e += '<select class="service_duration" i="' + i + '" name="sService' + this.name + "D" + i + '" id="sService' + this.name + "D" + i + '">';
                        for (var a = 1; a < 15; a++) e += '<option value="' + a + '" ' + (this.services[i].duration == a ? "selected" : "") + ">" + a + " min</option>";
                        for (a = 3; a <= 288; a++) e += '<option value="' + 5 * a + '" ' + (this.services[i].duration == 5 * a ? "selected" : "") + ">" + 5 * a + " min</option>";
                        e += "</select>", e += '<select class="service_paddingbefore" i="' + i + '" name="sService' + this.name + "before" + i + '" id="sService' + this.name + "before" + i + '">';
                        for (a = 0; a <= 72; a++) e += '<option value="' + 5 * a + '" ' + (this.services[i].pb == 5 * a ? "selected" : "") + ">" + 5 * a + " min</option>";
                        for (a = 7; a <= 12; a++) e += '<option value="' + 60 * a + '" ' + (this.services[i].pb == 60 * a ? "selected" : "") + ">" + a + " hours</option>";
                        e += "</select>", e += '<select class="service_paddingafter" i="' + i + '" name="sService' + this.name + "after" + i + '" id="sService' + this.name + "after" + i + '">';
                        for (a = 0; a <= 72; a++) e += '<option value="' + 5 * a + '" ' + (this.services[i].pa == 5 * a ? "selected" : "") + ">" + 5 * a + " min</option>";
                        for (a = 7; a <= 12; a++) e += '<option value="' + 60 * a + '" ' + (this.services[i].pa == 60 * a ? "selected" : "") + ">" + a + " hours</option>";
                        e += "</select>", e += '<div>Open hours<br /><select class="service_openhours" i="' + i + '" ></select></div>', e += '<a class="choice_down ui-icon ui-icon-arrowthick-1-s" i="' + i + '" n="' + (this.services.length - 1) + '" title="Down"></a><a class="choice_up ui-icon ui-icon-arrowthick-1-n" i="' + i + '" title="Up"></a><a class="choice_add ui-icon ui-icon-circle-plus" i="' + i + '" title="Add another choice."></a><a class="choice_remove ui-icon ui-icon-circle-minus" i="' + i + '" title="Delete this choice."></a><hr /></div>'
                    }
                    e += '<div><input type="checkbox"  name="sBSlotsCheckbox" id="sBSlotsCheckbox" ' + (this.bSlotsCheckbox ? "checked" : "") + "/> Generate time slots automatically based on service duration", e += '<div id="sBSlotsDiv" style="display:' + (this.bSlotsCheckbox ? "none" : "block") + '">Generate slots every <select class="BSlots" name="sBSlots" id="sBSlots">';
                    for (a = 1; a <= 288; a++) e += '<option value="' + 5 * a + '" ' + (this.bSlots == 5 * a ? "selected" : "") + ">" + 5 * a + " min</option>";
                    e += "</select></div></div>", e += '<div id="sBUsedSlotsDiv"><input type="checkbox"  name="sUsedSlotsCheckbox" id="sUsedSlotsCheckbox" ' + (this.usedSlotsCheckbox ? "checked" : "") + "/> Show used slots</div>", e += '<div><input type="checkbox"  name="sEmptySelectCheckbox" id="sEmptySelectCheckbox" ' + (this.emptySelectCheckbox ? "checked" : "") + "/> Make user choose service before display times", "undefined" != typeof tzfdhour_cmadmin && (e += '<div><input type="checkbox"  name="sAvoidOverlaping" id="sAvoidOverlaping" ' + (this.avoidOverlaping ? "checked" : "") + '/> Avoid overlaping between services [<a class="helptzfdform" text="If checked only one service will used for each time-slot. If un-checked each time-slot availability will be calculated separately for each service.">help?</a>]</div>'), e += '<div id="sEmptySelectDiv" style="display:' + (this.emptySelectCheckbox ? "block" : "none") + '">Text ', e += '<input type="text" name="sEmptySelect" id="sEmptySelect" value="' + this.emptySelect + '"></div></div>', e += "</div>", e += '<div class="choicesSet openhours"><label>Open hours: Default [<a class="helptzfdform" text="Open hours for all dates, for each weekday or specific date. Examples: \n\n - For all days: All days from 08:00 to 17:00 hours. \n\n - For specific weekdays: Mondays from 08:00 to 17:00 hours. \n\n - For specific dates: 27/12/2018 from 08:00 to 10:00 hours.">help?</a>]</label>', e += '<div id="openHoursSection"></div>', "undefined" != typeof tzfdhour_cmadmin && (e += '<input type="button" class="addOpenHours" value="Add Special Open Hours">'), e += "</div>", e += '<div class="working_dates"><label>Working dates </label><br /><input name="sWD0" id="sWD0" value="0" type="checkbox" ' + (this.working_dates[0] ? "checked" : "") + '/>Su<input name="sWD1" id="sWD1" value="1" type="checkbox" ' + (this.working_dates[1] ? "checked" : "") + '/>Mo<input name="sWD2" id="sWD2" value="2" type="checkbox" ' + (this.working_dates[2] ? "checked" : "") + '/>Tu<input name="sWD3" id="sWD3" value="3" type="checkbox" ' + (this.working_dates[3] ? "checked" : "") + '/>We<input name="sWD4" id="sWD4" value="4" type="checkbox" ' + (this.working_dates[4] ? "checked" : "") + '/>Th<input name="sWD5" id="sWD5" value="5" type="checkbox" ' + (this.working_dates[5] ? "checked" : "") + '/>Fr<input name="sWD6" id="sWD6" value="6" type="checkbox" ' + (this.working_dates[6] ? "checked" : "") + "/>Sa</div>";
                    var s = "";
                    for (i = 0; i < 7; i++) s += '<option value="' + i + '" ' + (i == this.firstDay ? "selected" : "") + ">" + t[i] + "</option>";
                    e += '<div><label>First Date</label><br /><label><select name="sFirstDay" id="sFirstDay">' + s + "</select></div>";
                    var o = "";
                    for (i = 1; i <= 12; i++) o += '<option value="' + i + '" ' + (i == this.numberOfMonths ? "selected" : "") + ">" + i + "</option>";
                    e += '<div><label>Military Time</label><br /><label><select name="sMilitaryTime" id="sMilitaryTime"><option value="1" ' + (1 == this.militaryTime ? "selected" : "") + '>Yes (24 hours)</option><option value="0" ' + (1 != this.militaryTime ? "selected" : "") + ">No (12 hours AM/PM)</option></select></div>", e += '<div><label>Number of months</label><br /><label><select name="sNumberOfMonths" id="sNumberOfMonths">' + o + "</select></div>";
                    var l = "";
                    for (i = 0; i <= 300; i++) l += '<option value="' + i + '" ' + (i == this.maxNumberOfApp ? "selected" : "") + ">" + (0 == i ? "Unlimited" : i) + "</option>";
                    e += '<div><label>Max number of appointments</label><br /><label><select name="sMaxNumberOfApp" id="sMaxNumberOfApp">' + l + "</select></div>", e += '<div><label>Default date [<a class="helptzfdform" text="You can put one of the following type of values into this field:\n\nEmpty: Leave empty for current date.\n\nDate: A Fixed date with the same date format indicated in the &quot;Date Format&quot; drop-down field.\n\nNumber: A number of days from today. For example 2 represents two days from today and -1 represents yesterday.\n\nString: A smart text indicating a relative date. Relative dates must contain value (number) and period pairs; valid periods are &quot;y&quot; for years, &quot;m&quot; for months, &quot;w&quot; for weeks, and &quot;d&quot; for days. For example, &quot;+1m +7d&quot; represents one month and seven days from today.">help?</a>]</label><br /><input class="medium" name="sDefaultDate" id="sDefaultDate" value="' + $.tzfdform.htmlEncode(this.defaultDate) + '" /></div>', e += '<div id="sdMindate"><label>Min date [<a class="helptzfdform" text="You can put one of the following type of values into this field:\n\nEmpty: No min Date.\n\nDate: A Fixed date with the same date format indicated in the &quot;Date Format&quot; drop-down field.\n\nNumber: A number of days from today. For example 2 represents two days from today and -1 represents yesterday.\n\nString: A smart text indicating a relative date. Relative dates must contain value (number) and period pairs; valid periods are &quot;y&quot; for years, &quot;m&quot; for months, &quot;w&quot; for weeks, &quot;d&quot; for days, and &quot;h&quot; for hours. For example, &quot;+1m +7d&quot; represents one month and seven days from today.">help?</a>] [Samples]</label> <br /><input class="medium" name="sMinDate" id="sMinDate" value="' + $.tzfdform.htmlEncode(this.minDate) + '" /></div>', e += '<div id="sdMaxdate"><label>Max date [<a class="helptzfdform" text="You can put one of the following type of values into this field:\n\nEmpty: No max Date.\n\nDate: A Fixed date with the same date format indicated in the &quot;Date Format&quot; drop-down field.\n\nNumber: A number of days from today. For example 2 represents two days from today and -1 represents yesterday.\n\nString: A smart text indicating a relative date. Relative dates must contain value (number) and period pairs; valid periods are &quot;y&quot; for years, &quot;m&quot; for months, &quot;w&quot; for weeks, and &quot;d&quot; for days. For example, &quot;+1m +7d&quot; represents one month and seven days from today.">help?</a>]</label><br /><input class="medium" name="sMaxDate" id="sMaxDate" value="' + $.tzfdform.htmlEncode(this.maxDate) + '" /></div>', e += '<div id="sdInvalidDates"><label>Invalid Dates [<a class="helptzfdform" text="To define some dates as invalid, enter the dates with the format: mm/dd/yyyy separated by comma; for example: 12/31/2014,02/20/2014 or by hyphen for intervals; for example: 12/20/2014-12/28/2014 ">help?</a>]</label><br /><textarea class="large" name="sInvalidDates" id="sInvalidDates" />' + $.tzfdform.htmlEncode(this.invalidDates) + "</textarea></div>", e += '<div><input type="checkbox" name="sShowDropdown" id="sShowDropdown" ' + (this.showDropdown ? "checked" : "") + '/><label>Show Dropdown Year and Month</label><div id="divdropdownRange" style="display:' + (this.showDropdown ? "" : "none") + '">Year Range [<a class="helptzfdform" text="The range of years displayed in the year drop-down: either relative to today\'s year (&quot;-nn:+nn&quot;), absolute (&quot;nnnn:nnnn&quot;), or combinations of these formats (&quot;nnnn:-nn&quot;)">help?</a>]: <input type="text" name="sDropdownRange" id="sDropdownRange" value="' + $.tzfdform.htmlEncode(this.dropdownRange) + '"/></div></div>', e += '<div><input type="checkbox" name="sShowEndTime" id="sShowEndTime" ' + (this.showEndTime ? "checked" : "") + '/><label>Show end time [<a class="helptzfdform" text="If enabled it will display the end time for each time-slot based in the duration of the selected service.">help?</a>]</label></div>', e += '<div><input type="checkbox" name="sShowTotalCost" id="sShowTotalCost" ' + (this.showTotalCost ? "checked" : "") + '/><label>Show Total Cost</label><div id="divTotalCostFormat" style="display:' + (this.showTotalCost ? "" : "none") + '">Total cost format [<a class="helptzfdform" text="The string {0} will be replaced with the calculated cost. Keep the {0} reference. You can edit the currency symbol or add additional text.">help?</a>]: <input type="text" name="sShowTotalCostFormat" id="sShowTotalCostFormat" value="' + $.tzfdform.htmlEncode(this.showTotalCostFormat) + '"/></div></div>';
                    var n = "";
                    for (i = 0; i <= this.services[0].capacity; i++) n += '<option value="' + i + '" ' + (i == this.quantityMin ? "selected" : "") + ">" + i + "</option>";
                    return e += '<div><input type="checkbox" name="sShowQuantity" id="sShowQuantity" ' + (this.showQuantity ? "checked" : "") + '/><label>Show quantity field [<a class="helptzfdform" text="If enabled a drop-down field will be displayed above the calendar to select the quantity to book o the selected service (useful for services with capacity greater than 1).">help?</a>]</label><div id="divQuantityMin" style="display:' + (this.showQuantity ? "" : "none") + '">From <select name="sQuantityMin" id="sQuantityMin" >' + n + "</select></div></div>", e += "<hr></hr>"
                }
            }), $.tzfdform.typeList.push({
                id: "facceptance",
                name: "Accept/GDPR",
                control_category: 1
            }), $.tzfdform.controls.facceptance = function() {}, $.extend($.tzfdform.controls.facceptance.prototype, $.tzfdform.controls.ffields.prototype, {
                title: "Accept terms and conditions",
                ftype: "facceptance",
                value: "I accept",
                url: "",
                message: "",
                required: !0,
                display: function() {
                    var e = '<div class="one_column"><input class="field" disabled="true" type="checkbox"/> ' + $.tzfdform.htmlEncode(this.title) + (this.required ? "*" : "") + "</div>";
                    return '<div class="fields ' + this.name + '" id="field' + this.form_identifier + "-" + this.index + '" title="' + this.name + '"><div class="arrow ui-icon ui-icon-play "></div><div title="Delete" class="remove ui-icon ui-icon-trash "></div><div title="Duplicate" class="copy ui-icon ui-icon-copy "></div><div class="dfield">' + e + '<span class="uh">' + $.tzfdform.htmlEncode(this.userhelp) + '</span></div><div class="clearer"></div></div>'
                },
                editItemEvents: function() {
                    evt = [{
                        s: "#sValue",
                        e: "change keyup",
                        l: "value"
                    }, {
                        s: "#sURL",
                        e: "change keyup",
                        l: "url"
                    }, {
                        s: "#sMessage",
                        e: "change keyup",
                        l: "message"
                    }], $.tzfdform.controls.ffields.prototype.editItemEvents.call(this, evt)
                },
                showRequired: function(e) {
                    return '<div><input type="checkbox" checked disabled><label>Acceptance fields are always required</label></div>'
                },
                showUserhelp: function() {
                    return ""
                },
                showValue: function() {
                    return '<div><label>Value</label><input class="large" type="text" name="sValue" id="sValue" value="' + $.tzfdform.htmlEncode(this.value) + '"></div>'
                },
                showURL: function() {
                    return '<div><label>URL to the Consent and Acknowledgement page</label><input class="large" type="text" name="sURL" id="sURL" value="' + $.tzfdform.htmlEncode(this.url) + '"></div>'
                },
                showMessage: function() {
                    return '<div><label>- or - enter the Consent and Acknowledgement text</label><textarea class="large" name="sMessage" id="sMessage" style="height:150px;">' + $.tzfdform.textareaEncode(this.message) + "</textarea></div>"
                },
                showCsslayout: function() {
                    return $.tzfdform.controls.ffields.prototype.showCsslayout.call(this) + '<div style="color: #666;border: 1px solid #EF7E59;display: block;padding: 5px;background: #FBF0EC;border-radius: 4px;text-align: center;margin-top:20px;">The Acceptance control helps to make the form comply with one of requirements of the General Data Protection Regulation (GDPR)</div>'
                },
                showSpecialDataInstance: function() {
                    return this.showValue() + this.showURL() + this.showMessage()
                }
            }), $.tzfdform.typeList.push({
                id: "fcurrency",
                name: "Currency",
                control_category: 3
            }), $.tzfdform.controls.fcurrency = function() {}, $.extend($.tzfdform.controls.fcurrency.prototype, $.tzfdform.controls.ffields.prototype, {
                title: "Currency",
                ftype: "fcurrency",
                predefined: "",
                predefinedClick: !1,
                required: !1,
                size: "small",
                readonly: !1,
                currencySymbol: "$",
                currencyText: "USD",
                thousandSeparator: ",",
                centSeparator: ".",
                min: "",
                max: "",
                formatDynamically: !1,
                display: function() {
                    return '<div class="fields ' + this.name + '" id="field' + this.form_identifier + '-' + this.index + '"><div class="arrow ui-icon ui-icon-play "></div><div title="Delete" class="remove ui-icon ui-icon-trash "></div><div title="Duplicate" class="copy ui-icon ui-icon-copy "></div><label>' + $.tzfdform.htmlEncode(this.title) + '' + ((this.required) ? "*" : "") + '</label><div class="dfield"><span class="uh">' + $.tzfdform.htmlEncode(this.userhelp) + '</span></div><div class="clearer"></div></div>'
                },
                editItemEvents: function() {}
            }), $.tzfdform.typeList.push({
                id: "fnumber",
                name: "Number",
                control_category: 3
            }), $.tzfdform.controls.fnumber = function() {}, $.extend($.tzfdform.controls.fnumber.prototype, $.tzfdform.controls.ffields.prototype, {
                title: "Number",
                ftype: "fnumber",
                predefined: "",
                predefinedClick: !1,
                required: !1,
                size: "small",
                thousandSeparator: "",
                decimalSymbol: ".",
                min: "",
                max: "",
                dformat: "digits",
                formats: new Array("digits", "number"),
                display: function() {
                    return '<div class="fields ' + this.name + '" id="field' + this.form_identifier + '-' + this.index + '"><div class="arrow ui-icon ui-icon-play "></div><div title="Delete" class="remove ui-icon ui-icon-trash "></div><div title="Duplicate" class="copy ui-icon ui-icon-copy "></div><label>' + $.tzfdform.htmlEncode(this.title) + '' + ((this.required) ? "*" : "") + '</label><div class="dfield"><span class="uh">' + $.tzfdform.htmlEncode(this.userhelp) + '</span></div><div class="clearer"></div></div>'
                },
                editItemEvents: function() {
                    $.tzfdform.controls.ffields.prototype.editItemEvents.call(this)
                }
            }), $.tzfdform.typeList.push({
                id: "fdate",
                name: "Date Time",
                control_category: 3
            }), $.tzfdform.controls.fdate = function() {}, $.extend($.tzfdform.controls.fdate.prototype, $.tzfdform.controls.ffields.prototype, {
                title: "Date",
                ftype: "fdate",
                predefined: "",
                predefinedClick: !1,
                size: "medium",
                required: !1,
                dformat: "mm/dd/yyyy",
                tformat: "24",
                showDropdown: !1,
                dropdownRange: "-10:+10",
                minDate: "",
                maxDate: "",
                invalidDates: "",
                minHour: 0,
                maxHour: 23,
                minMinute: 0,
                maxMinute: 59,
                stepHour: 1,
                stepMinute: 1,
                showTimepicker: !1,
                defaultDate: "",
                defaultTime: "",
                working_dates: [!0, !0, !0, !0, !0, !0, !0],
                formats: new Array("mm/dd/yyyy", "dd/mm/yyyy"),
                display: function() {
                    return '<div class="fields ' + this.name + '" id="field' + this.form_identifier + '-' + this.index + '"><div class="arrow ui-icon ui-icon-play "></div><div title="Delete" class="remove ui-icon ui-icon-trash "></div><div title="Duplicate" class="copy ui-icon ui-icon-copy "></div><label>' + $.tzfdform.htmlEncode(this.title) + '' + ((this.required) ? "*" : "") + '</label><div class="dfield"><span class="uh">' + $.tzfdform.htmlEncode(this.userhelp) + '</span></div><div class="clearer"></div></div>'
                },
                editItemEvents: function() {
                    $.tzfdform.controls.ffields.prototype.editItemEvents.call(this)
                }
            }), $.tzfdform.typeList.push({
                id: "ftextarea",
                name: "Text Area",
                control_category: 3
            }), $.tzfdform.controls.ftextarea = function() {}, $.extend($.tzfdform.controls.ftextarea.prototype, $.tzfdform.controls.ffields.prototype, {
                title: "Untitled",
                ftype: "ftextarea",
                predefined: "",
                predefinedClick: !1,
                required: !1,
                size: "medium",
                minlength: "",
                maxlength: "",
                rows: 4,
                display: function() {
                    return '<div class="fields ' + this.name + '" id="field' + this.form_identifier + '-' + this.index + '"><div class="arrow ui-icon ui-icon-play "></div><div title="Delete" class="remove ui-icon ui-icon-trash "></div><div title="Duplicate" class="copy ui-icon ui-icon-copy "></div><label>' + $.tzfdform.htmlEncode(this.title) + '' + ((this.required) ? "*" : "") + '</label><div class="dfield"><span class="uh">' + $.tzfdform.htmlEncode(this.userhelp) + '</span></div><div class="clearer"></div></div>'
                },
                editItemEvents: function() {
                    $.tzfdform.controls.ffields.prototype.editItemEvents.call(this)
                }
            }), $.tzfdform.typeList.push({
                id: "fcheck",
                name: "Checkboxes",
                control_category: 3
            }), $.tzfdform.controls.fcheck = function() {}, $.extend($.tzfdform.controls.fcheck.prototype, $.tzfdform.controls.ffields.prototype, {
                title: "Check All That Apply",
                ftype: "fcheck",
                layout: "one_column",
                required: !1,
                showDep: !1,
                display: function() {
                    return '<div class="fields ' + this.name + '" id="field' + this.form_identifier + '-' + this.index + '"><div class="arrow ui-icon ui-icon-play "></div><div title="Delete" class="remove ui-icon ui-icon-trash "></div><div title="Duplicate" class="copy ui-icon ui-icon-copy "></div><label>' + $.tzfdform.htmlEncode(this.title) + '' + ((this.required) ? "*" : "") + '</label><div class="dfield"><span class="uh">' + $.tzfdform.htmlEncode(this.userhelp) + '</span></div><div class="clearer"></div></div>'
                },
                editItemEvents: function() {
                    $.tzfdform.controls.ffields.prototype.editItemEvents.call(this)
                }
            }), $.tzfdform.typeList.push({
                id: "fradio",
                name: "Radio Buttons",
                control_category: 3
            }), $.tzfdform.controls.fradio = function() {}, $.extend($.tzfdform.controls.fradio.prototype, $.tzfdform.controls.ffields.prototype, {
                title: "Select a Choice",
                ftype: "fradio",
                layout: "one_column",
                required: !1,
                choiceSelected: "",
                showDep: !1,
                display: function() {
                    return '<div class="fields ' + this.name + '" id="field' + this.form_identifier + '-' + this.index + '"><div class="arrow ui-icon ui-icon-play "></div><div title="Delete" class="remove ui-icon ui-icon-trash "></div><div title="Duplicate" class="copy ui-icon ui-icon-copy "></div><label>' + $.tzfdform.htmlEncode(this.title) + '' + ((this.required) ? "*" : "") + '</label><div class="dfield"><span class="uh">' + $.tzfdform.htmlEncode(this.userhelp) + '</span></div><div class="clearer"></div></div>'
                },
                editItemEvents: function() {
                    $.tzfdform.controls.ffields.prototype.editItemEvents.call(this)
                }
            }), $.tzfdform.typeList.push({
                id: "fdropdown",
                name: "Dropdown",
                control_category: 3
            }), $.tzfdform.controls.fdropdown = function() {}, $.extend($.tzfdform.controls.fdropdown.prototype, $.tzfdform.controls.ffields.prototype, {
                title: "Select a Choice",
                ftype: "fdropdown",
                size: "medium",
                required: !1,
                choiceSelected: "",
                showDep: !1,
                display: function() {
                    return '<div class="fields ' + this.name + '" id="field' + this.form_identifier + '-' + this.index + '"><div class="arrow ui-icon ui-icon-play "></div><div title="Delete" class="remove ui-icon ui-icon-trash "></div><div title="Duplicate" class="copy ui-icon ui-icon-copy "></div><label>' + $.tzfdform.htmlEncode(this.title) + '' + ((this.required) ? "*" : "") + '</label><div class="dfield"><span class="uh">' + $.tzfdform.htmlEncode(this.userhelp) + '</span></div><div class="clearer"></div></div>'
                },
                editItemEvents: function() {
                    $.tzfdform.controls.ffields.prototype.editItemEvents.call(this)
                }
            }), $.tzfdform.typeList.push({
                id: "ffile",
                name: "Upload File",
                control_category: 3
            }), $.tzfdform.controls.ffile = function() {}, $.extend($.tzfdform.controls.ffile.prototype, $.tzfdform.controls.ffields.prototype, {
                title: "Untitled",
                ftype: "ffile",
                required: !1,
                size: "medium",
                accept: "",
                upload_size: "",
                multiple: !1,
                display: function() {
                    return '<div class="fields ' + this.name + '" id="field' + this.form_identifier + '-' + this.index + '"><div class="arrow ui-icon ui-icon-play "></div><div title="Delete" class="remove ui-icon ui-icon-trash "></div><div title="Duplicate" class="copy ui-icon ui-icon-copy "></div><label>' + $.tzfdform.htmlEncode(this.title) + '' + ((this.required) ? "*" : "") + '</label><div class="dfield"><span class="uh">' + $.tzfdform.htmlEncode(this.userhelp) + '</span></div><div class="clearer"></div></div>'
                },
                editItemEvents: function() {
                    $.tzfdform.controls.ffields.prototype.editItemEvents.call(this)
                }
            }), $.tzfdform.typeList.push({
                id: "fpassword",
                name: "Password",
                control_category: 3
            }), $.tzfdform.controls.fpassword = function() {}, $.extend($.tzfdform.controls.fpassword.prototype, $.tzfdform.controls.ffields.prototype, {
                title: "Untitled",
                ftype: "fpassword",
                predefined: "",
                predefinedClick: !1,
                required: !1,
                size: "medium",
                minlength: "",
                maxlength: "",
                equalTo: "",
                display: function() {
                    return '<div class="fields ' + this.name + '" id="field' + this.form_identifier + '-' + this.index + '"><div class="arrow ui-icon ui-icon-play "></div><div title="Delete" class="remove ui-icon ui-icon-trash "></div><div title="Duplicate" class="copy ui-icon ui-icon-copy "></div><label>' + $.tzfdform.htmlEncode(this.title) + '' + ((this.required) ? "*" : "") + '</label><div class="dfield"><span class="uh">' + $.tzfdform.htmlEncode(this.userhelp) + '</span></div><div class="clearer"></div></div>'
                },
                editItemEvents: function() {
                    $.tzfdform.controls.ffields.prototype.editItemEvents.call(this)
                }
            }), $.tzfdform.typeList.push({
                id: "fPhone",
                name: "Phone field",
                control_category: 1
            }), $.tzfdform.controls.fPhone = function() {}, $.extend($.tzfdform.controls.fPhone.prototype, $.tzfdform.controls.ffields.prototype, {
                title: "Phone",
                ftype: "fPhone",
                required: !1,
                readonly: !1,
                dformat: "### ### ####",
                predefined: "888 888 8888",
                predefinedClick: !0,
                display: function() {
                    for (var e = "", t = this.dformat.split(" "), i = this.predefined.split(" "), a = 0; a < i.length; a++) "" == $.trim(i[a]) && i.splice(a, 1);
                    for (a = 0; a < t.length; a++) "" != $.trim(t[a]) && (e += '<div class="uh_phone" ><input type="text" class="field disabled" style="width:' + 15 * $.trim(t[a]).length + 'px" value="' + (i[a] ? i[a] : "") + '" maxlength="' + $.trim(t[a]).length + '" /><div class="l">' + $.trim(t[a]) + "</div></div>");
                    return '<div class="fields ' + this.name + '" id="field' + this.form_identifier + "-" + this.index + '" title="' + this.name + '"><div class="arrow ui-icon ui-icon-play "></div><div title="Delete" class="remove ui-icon ui-icon-trash "></div><div title="Duplicate" class="copy ui-icon ui-icon-copy "></div><label>' + this.title + (this.required ? "*" : "") + '</label><div class="dfield">' + e + '<span class="uh">' + this.userhelp + '</span></div><div class="clearer"></div></div>'
                },
                editItemEvents: function() {
                    $.tzfdform.controls.ffields.prototype.editItemEvents.call(this, [{
                        s: "#sFormat",
                        e: "change keyup",
                        l: "dformat"
                    }])
                },
                showFormatIntance: function() {
                    return '<div><label>Number Format</label><br /><input type="text" name="sFormat" id="sFormat" value="' + $.tzfdform.htmlEncode(this.dformat) + '" class="large" /></div>'
                }
            }), $.tzfdform.typeList.push({
                id: "fCommentArea",
                name: "Instruct. Text",
                control_category: 3
            }), $.tzfdform.controls.fCommentArea = function() {}, $.extend($.tzfdform.controls.fCommentArea.prototype, $.tzfdform.controls.ffields.prototype, {
                title: "Comments here",
                ftype: "fCommentArea",
                userhelp: "A description of the section goes here.",
                display: function() {
                    return '<div class="fields ' + this.name + '" id="field' + this.form_identifier + '-' + this.index + '"><div class="arrow ui-icon ui-icon-play "></div><div title="Delete" class="remove ui-icon ui-icon-trash "></div><div title="Duplicate" class="copy ui-icon ui-icon-copy "></div><label>' + $.tzfdform.htmlEncode(this.title) + '' + ((this.required) ? "*" : "") + '</label><div class="dfield"><span class="uh">' + $.tzfdform.htmlEncode(this.userhelp) + '</span></div><div class="clearer"></div></div>'
                },
                editItemEvents: function() {
                    $.tzfdform.controls.ffields.prototype.editItemEvents.call(this)
                }
            }), $.tzfdform.typeList.push({
                id: "fhidden",
                name: "Hidden",
                control_category: 3
            }), $.tzfdform.controls.fhidden = function() {}, $.extend($.tzfdform.controls.fhidden.prototype, $.tzfdform.controls.ffields.prototype, {
                title: "Hidden",
                ftype: "fhidden",
                predefined: "",
                display: function() {
                    return '<div class="fields ' + this.name + '" id="field' + this.form_identifier + '-' + this.index + '"><div class="arrow ui-icon ui-icon-play "></div><div title="Delete" class="remove ui-icon ui-icon-trash "></div><div title="Duplicate" class="copy ui-icon ui-icon-copy "></div><label>' + $.tzfdform.htmlEncode(this.title) + '' + ((this.required) ? "*" : "") + '</label><div class="dfield"><span class="uh">' + $.tzfdform.htmlEncode(this.userhelp) + '</span></div><div class="clearer"></div></div>'
                },
                editItemEvents: function() {
                    $.tzfdform.controls.ffields.prototype.editItemEvents.call(this)
                }
            }), $.tzfdform.typeList.push({
                id: "fSectionBreak",
                name: "Section break",
                control_category: 3
            }), $.tzfdform.controls.fSectionBreak = function() {}, $.extend($.tzfdform.controls.fSectionBreak.prototype, $.tzfdform.controls.ffields.prototype, {
                title: "Section Break",
                ftype: "fSectionBreak",
                userhelp: "A description of the section goes here.",
                display: function() {
                    return '<div class="fields ' + this.name + '" id="field' + this.form_identifier + '-' + this.index + '"><div class="arrow ui-icon ui-icon-play "></div><div title="Delete" class="remove ui-icon ui-icon-trash "></div><div title="Duplicate" class="copy ui-icon ui-icon-copy "></div><label>' + $.tzfdform.htmlEncode(this.title) + '' + ((this.required) ? "*" : "") + '</label><div class="dfield"><span class="uh">' + $.tzfdform.htmlEncode(this.userhelp) + '</span></div><div class="clearer"></div></div>'
                },
                editItemEvents: function() {
                    $.tzfdform.controls.ffields.prototype.editItemEvents.call(this)
                }
            }), $.tzfdform.typeList.push({
                id: "fPageBreak",
                name: "Page break",
                control_category: 3
            }), $.tzfdform.controls.fPageBreak = function() {}, $.extend($.tzfdform.controls.fPageBreak.prototype, $.tzfdform.controls.ffields.prototype, {
                title: "Page Break",
                ftype: "fPageBreak",
                display: function() {
                    return '<div class="fields ' + this.name + '" id="field' + this.form_identifier + '-' + this.index + '"><div class="arrow ui-icon ui-icon-play "></div><div title="Delete" class="remove ui-icon ui-icon-trash "></div><div title="Duplicate" class="copy ui-icon ui-icon-copy "></div><label>' + $.tzfdform.htmlEncode(this.title) + '' + ((this.required) ? "*" : "") + '</label><div class="dfield"><span class="uh">' + $.tzfdform.htmlEncode(this.userhelp) + '</span></div><div class="clearer"></div></div>'
                },
                editItemEvents: function() {
                    $.tzfdform.controls.ffields.prototype.editItemEvents.call(this)
                }
            }), $.tzfdform.typeList.push({
                id: "fsummary",
                name: "Summary",
                control_category: 3
            }), $.tzfdform.controls.fsummary = function() {}, $.extend($.tzfdform.controls.fsummary.prototype, $.tzfdform.controls.ffields.prototype, {
                title: "Summary",
                ftype: "fsummary",
                fields: "",
                titleClassname: "summary-field-title",
                valueClassname: "summary-field-value",
                display: function() {
                    return '<div class="fields ' + this.name + '" id="field' + this.form_identifier + '-' + this.index + '"><div class="arrow ui-icon ui-icon-play "></div><div title="Delete" class="remove ui-icon ui-icon-trash "></div><div title="Duplicate" class="copy ui-icon ui-icon-copy "></div><label>' + $.tzfdform.htmlEncode(this.title) + '' + ((this.required) ? "*" : "") + '</label><div class="dfield"><span class="uh">' + $.tzfdform.htmlEncode(this.userhelp) + '</span></div><div class="clearer"></div></div>'
                },
                editItemEvents: function() {
                    $.tzfdform.controls.ffields.prototype.editItemEvents.call(this)
                }
            }), $.tzfdform.controls.datasource = function() {
                this.list = {
                    database: {
                        title: "Database",
                        databaseData: {
                            host: "",
                            user: "",
                            pass: "",
                            database: ""
                        },
                        queryData: {
                            active: "structure",
                            query: "",
                            value: "",
                            text: "",
                            table: "",
                            where: "",
                            orderby: "",
                            limit: ""
                        },
                        show: function(e) {
                            var t = "<div>Database Connection</div>";
                            return t += '<div><label>Host:<label><input class="large" name="sHost" id="sHost" value="' + this.databaseData.host + '" /></div>', t += '<div><label>Username:<label><input class="large" name="sUser" id="sUser" value="' + this.databaseData.user + '" /></div>', t += '<div><label>Password:<label><input class="large" name="sPass" id="sPass" value="' + this.databaseData.pass + '" /></div>', t += '<div><label>Database:<label><input class="large" name="sDatabase" id="sDatabase" value="' + this.databaseData.database + '" /></div>', t += '<div><input type="button" class="button" name="sTestConnection" id="sTestConnection" value="Test Connection" style="float:right;margin:5px 0;" /></div>', t += '<div style="clear:both;"></div>', t += '<div><label><input type="radio" name="sQueryType" id="sQueryType" value="structure" ' + ("structure" == this.queryData.active ? "CHECKED" : "") + ' /> Query Structure</label><label><input type="radio" name="sQueryType" id="sQueryType" value="query" ' + ("query" == this.queryData.active ? "CHECKED" : "") + " /> Custom Query</label></div>", t += '<div id="databaseQueryData_structure" class="queryType" style="display:' + ("structure" == this.queryData.active ? "block" : "none") + ';" >', t += '<div><label>Column for values:<label><input class="large" name="sQueryValue" id="sQueryValue" value="' + this.queryData.value + '" /></div>', "pair" == e && (t += '<div><label>Column for texts:<label><input class="large" name="sQueryText" id="sQueryText" value="' + this.queryData.text + '" /></div>'), t += '<div><label>Table name:<label><input class="large" name="sQueryTable" id="sQueryTable" value="' + this.queryData.table + '" /></div>', t += '<div><label>Condition:<label><input class="large" name="sQueryWhere" id="sQueryWhere" value="' + this.queryData.where + '" /></div>', t += '<div><label>Order by:<label><input class="large" name="sQueryOrderBy" id="sQueryOrderBy" value="' + this.queryData.orderby + '" /></div>', t += '<div><label>Limit:<label><input class="large" name="sQueryLimit" id="sQueryLimit" value="' + this.queryData.limit + '" /></div>', t += "</div>", t += '<div id="databaseQueryData_query" class="queryType" style="display:' + ("query" == this.queryData.active ? "block" : "none") + ';" >', t += '<div><label>Type the query:<label><input class="large" name="sCustomQuery" id="sCustomQuery" value="' + this.queryData.query + '" /></div>', t += "</div>", t += '<div><input type="button" class="button" name="sTestQuery" id="sTestQuery" value="Test Query" style="float:right;margin:5px 0;" /></div>', t += '<div style="clear:both;"></div>'
                        },
                        events: function() {
                            $("#sHost").bind("keyup change", {
                                obj: this
                            }, (function(e) {
                                e.data.obj.databaseData.host = $.trim($(this).val()), $.tzfdform.reloadItems()
                            })), $("#sUser").bind("keyup change", {
                                obj: this
                            }, (function(e) {
                                e.data.obj.databaseData.user = $.trim($(this).val()), $.tzfdform.reloadItems()
                            })), $("#sPass").bind("keyup change", {
                                obj: this
                            }, (function(e) {
                                e.data.obj.databaseData.pass = $.trim($(this).val()), $.tzfdform.reloadItems()
                            })), $("#sDatabase").bind("keyup change", {
                                obj: this
                            }, (function(e) {
                                e.data.obj.databaseData.database = $.trim($(this).val()), $.tzfdform.reloadItems()
                            })), $('[name="sQueryType"]').bind("click", {
                                obj: this
                            }, (function(e) {
                                $(".queryType").hide(), $("#databaseQueryData_" + e.target.value).show(), e.data.obj.queryData.active = $.trim($(this).val())
                            })), $("#sQueryValue").bind("keyup change", {
                                obj: this
                            }, (function(e) {
                                e.data.obj.queryData.value = $.trim($(this).val()), $.tzfdform.reloadItems()
                            })), $("#sQueryText").bind("keyup change", {
                                obj: this
                            }, (function(e) {
                                e.data.obj.queryData.text = $.trim($(this).val()), $.tzfdform.reloadItems()
                            })), $("#sQueryTable").bind("keyup change", {
                                obj: this
                            }, (function(e) {
                                e.data.obj.queryData.table = $.trim($(this).val()), $.tzfdform.reloadItems()
                            })), $("#sQueryWhere").bind("keyup change", {
                                obj: this
                            }, (function(e) {
                                e.data.obj.queryData.where = $.trim($(this).val()), $.tzfdform.reloadItems()
                            })), $("#sQueryOrderBy").bind("keyup change", {
                                obj: this
                            }, (function(e) {
                                e.data.obj.queryData.orderby = $.trim($(this).val()), $.tzfdform.reloadItems()
                            })), $("#sQueryLimit").bind("keyup change", {
                                obj: this
                            }, (function(e) {
                                e.data.obj.queryData.limit = $.trim($(this).val()), $.tzfdform.reloadItems()
                            })), $("#sCustomQuery").bind("keyup change", {
                                obj: this
                            }, (function(e) {
                                e.data.obj.queryData.query = $.trim($(this).val()), $.tzfdform.reloadItems()
                            })), $("#sTestConnection").bind("click", {
                                obj: this
                            }, (function(e) {
                                var t = $(this).parents("form").attr("action");
                                $.ajax({
                                    url: t,
                                    cache: !1,
                                    data: $.extend({
                                        cffaction: "test_db_connection"
                                    }, e.data.obj.databaseData),
                                    success: function(e) {
                                        alert(e)
                                    }
                                })
                            })), $("#sTestQuery").bind("click", {
                                obj: this
                            }, (function(e) {
                                var t = $(this).parents("form").attr("action");
                                $.ajax({
                                    url: t,
                                    cache: !1,
                                    data: $.extend({
                                        cffaction: "test_db_query"
                                    }, e.data.obj.databaseData, e.data.obj.queryData),
                                    success: function(e) {
                                        alert(e)
                                    }
                                })
                            }))
                        }
                    },
                    csv: {
                        title: "CSV",
                        csvData: {
                            text: 0,
                            value: 0,
                            file: "",
                            fields: [],
                            headline: !1,
                            delimiter: "tabulator",
                            character: ",",
                            rows: []
                        },
                        show: function() {
                            var e = "<div>CSV Import</div>",
                                t = "",
                                i = "";
                            for (var a in this.csvData.fields) t += '<option value="' + a + '" ' + (a == this.csvData.text ? "SELECTED" : "") + " >" + $("<i>").text(this.csvData.fields[a]).html() + "</option>", i += '<option value="' + a + '" ' + (a == this.csvData.value ? "SELECTED" : "") + " >" + $("<i>").text(this.csvData.fields[a]).html() + "</option>";
                            return e += '<div><label>Select CSV file:<label><input type="file" class="large" name="sCSVLocation" id="sCSVLocation" /></div>', e += '<div><label>Use headline: <input type="checkbox" name="sCSVUseHeadline" id="sCSVUseHeadline" ' + (this.csvData.headline ? "CHECKED" : "") + " /><label></div>", e += '<div><label>Delimiter:</label>&nbsp;&nbsp;<label><input type="radio" name="sCSVDelimiter" id="sCSVDelimiter" value="tabulator" ' + ("tabulator" == this.csvData.delimiter ? "CHECKED" : "") + ' /> Tabulator<label>&nbsp;&nbsp;<label><input type="radio" name="sCSVDelimiter" id="sCSVDelimiter" value="character" ' + ("character" == this.csvData.delimiter ? "CHECKED" : "") + ' /> Character <input type="text" class="small" name="sCSVCharacter" id="sCSVCharacter" value="' + this.csvData.character + '" /><label></div>', e += '<div><input type="button" class="button" name="sCSVImport" id="sCSVImport" value="Import CSV" style="float:right;margin:5px 0;" /></div>', e += '<div><label>Select column for texts:<label><select class="large" name="sCSVTexts" id="sCSVTexts">' + t + "</select></div>", e += '<div><label>Select column for values:<label><select class="large" name="sCSVValues" id="sCSVValues">' + i + "</select></div>", e += '<div style="clear:both;"></div>'
                        },
                        events: function() {
                            $("#sCSVUseHeadline").bind("click", {
                                obj: this
                            }, (function(e) {
                                e.data.obj.csvData.headline = $(this).is(":checked"), $.tzfdform.reloadItems()
                            })), $("#sCSVTexts").bind("change", {
                                obj: this
                            }, (function(e) {
                                e.data.obj.csvData.text = $(this).val(), $.tzfdform.reloadItems()
                            })), $("#sCSVValues").bind("change", {
                                obj: this
                            }, (function(e) {
                                e.data.obj.csvData.value = $(this).val(), $.tzfdform.reloadItems()
                            })), $("#sCSVImport").bind("click", {
                                obj: this
                            }, (function(e) {
                                e.data.obj.csvData.fields = [], e.data.obj.csvData.rows = [], e.data.obj.csvData.text = 0, e.data.obj.csvData.value = 0, e.data.obj.csvData.file = "";
                                var t = {
                                    config: {
                                        header: e.data.obj.csvData.headline,
                                        dynamicTyping: !1,
                                        delimiter: "tabulator" != e.data.obj.csvData.delimiter ? e.data.obj.csvData.character : "",
                                        preview: 0
                                    },
                                    complete: function(t, i, a, s) {
                                        function o(t) {
                                            for (var i = 0; i < t; i++) e.data.obj.csvData.fields.push("Field_" + i)
                                        }
                                        0 == t.errors.length ? (void 0 !== t.results.fields ? (e.data.obj.csvData.fields = t.results.fields, e.data.obj.csvData.text = e.data.obj.csvData.value = t.results.fields[0]) : void 0 !== t.results.rows ? t.results.rows.length && o(t.results.rows[0].length) : void 0 !== t.results && o(t.results[0].length), e.data.obj.csvData.text = e.data.obj.csvData.value = 0, e.data.obj.csvData.rows = void 0 !== t.results.rows ? t.results.rows : t.results, e.data.obj.csvData.file = $("#sCSVLocation").val(), $.tzfdform.reloadItems(), $("#datasourceSettings").html(e.data.obj.show()), e.data.obj.events()) : alert("Error, checks the CSV file structure")
                                    }
                                };
                                ( function( csvInput, opts )
{
	if ( ! csvInput || ! csvInput.files || ! csvInput.files.length || typeof Papa === 'undefined' ) return;
	var header = ( typeof opts.config.header === 'boolean' ) ? opts.config.header : true;
	var d = opts.config.delimiter;
	var delimiter = ( typeof d === 'string' && d.length === 1 && d !== '"' && d !== '\n' ) ? d : '';
	Papa.parse( csvInput.files[ 0 ], {
		header: header,
		dynamicTyping: false,
		delimiter: delimiter,
		preview: 0,
		skipEmptyLines: 'greedy',
		complete: function( parsed, file )
		{
			opts.complete( {
				errors: parsed.errors,
				results: header ? { fields: parsed.meta.fields || [], rows: parsed.data } : parsed.data
			}, file, csvInput, null );
		}
	} );
} )( $( '#sCSVLocation' )[ 0 ], t );
                            }))
                        }
                    },
                    posttype: {
                        title: "Post Type",
                        posttypeData: {
                            posttype: "",
                            value: "ID",
                            text: "post_title",
                            last: "",
                            id: ""
                        },
                        loadPostTypes: function() {
                            var e = this,
                                t = $("#sPostType"),
                                i = t.parents("form").attr("action");
                            $.ajax({
                                dataType: "json",
                                url: i,
                                cache: !1,
                                data: {
                                    cffaction: "get_post_types"
                                },
                                success: function(i) {
                                    var a = "",
                                        s = "" != e.posttypeData.posttype ? e.posttypeData.posttype : Object.keys(i)[0];
                                    for (var o in i) a += '<option value="' + o + '" ' + (o == s ? "SELECTED" : "") + " >" + i[o] + "</option>";
                                    t.html(a).change()
                                }
                            })
                        },
                        show: function(e) {
                            var t = "<div>Select Post Type</div>",
                                i = ["ID", "post_title", "post_excerpt", "post_content"],
                                a = "",
                                s = "";
                            for (var o in i) a += '<option value="' + i[o] + '" ' + (this.posttypeData.value == i[o] ? "SELECTED" : "") + " >" + i[o] + "</option>", s += '<option value="' + i[o] + '" ' + (this.posttypeData.text == i[o] ? "SELECTED" : "") + " >" + i[o] + "</option>";
                            return t += '<div><label>Post Type:<label><select class="large" name="sPostType" id="sPostType"></select></div>', t += '<div><label>Attribute for values:<label><select class="large" name="sPostTypeValue" id="sPostTypeValue">' + a + "</select></div>", "pair" == e ? (this.posttypeData.id = "", t += '<div><label>Attribute for texts:<label><select class="large" name="sPostTypeText" id="sPostTypeText">' + s + "</select></div>", t += '<div><label>Display the last:<label><input type="text" class="large" name="sPostTypeLast" id="sPostTypeLast" value="' + this.posttypeData.last + '" /></div>') : t += '<div><label>Type a post ID:<label><input class="large" name="sPostId" id="sPostId" type="text" value="' + this.posttypeData.id + '" /></div>', t += '<div style="clear:both;"></div>'
                        },
                        events: function() {
                            $("#sPostType").bind("change", {
                                obj: this
                            }, (function(e) {
                                e.data.obj.posttypeData.posttype = $(this).val(), $.tzfdform.reloadItems()
                            })), $("#sPostTypeText").bind("change", {
                                obj: this
                            }, (function(e) {
                                e.data.obj.posttypeData.text = $(this).val(), $.tzfdform.reloadItems()
                            })), $("#sPostTypeValue").bind("change", {
                                obj: this
                            }, (function(e) {
                                e.data.obj.posttypeData.value = $(this).val(), $.tzfdform.reloadItems()
                            })), $("#sPostTypeLast").bind("keyup change", {
                                obj: this
                            }, (function(e) {
                                e.data.obj.posttypeData.last = $.trim($(this).val()), $.tzfdform.reloadItems()
                            })), $("#sPostId").bind("keyup change", {
                                obj: this
                            }, (function(e) {
                                e.data.obj.posttypeData.id = $.trim($(this).val()), $.tzfdform.reloadItems()
                            })), this.loadPostTypes()
                        }
                    },
                    taxonomy: {
                        title: "Taxonomy",
                        taxonomyData: {
                            taxonomy: "",
                            value: "term_id",
                            text: "name",
                            id: "",
                            slug: ""
                        },
                        loadTaxonomies: function() {
                            var e = this,
                                t = $("#sTaxonomy"),
                                i = t.parents("form").attr("action");
                            $.ajax({
                                dataType: "json",
                                url: i,
                                cache: !1,
                                data: {
                                    cffaction: "get_available_taxonomies"
                                },
                                success: function(i) {
                                    var a = "",
                                        s = "" != e.taxonomyData.taxonomy ? e.taxonomyData.taxonomy : Object.keys(i)[0];
                                    for (var o in i) a += '<option value="' + o + '" ' + (o == s ? "SELECTED" : "") + " >" + i[o].labels.name + "</option>";
                                    t.html(a).change()
                                }
                            })
                        },
                        show: function(e) {
                            var t = "<div>Select Taxonomy</div>",
                                i = ["term_id", "name", "slug"],
                                a = "",
                                s = "";
                            for (var o in t += '<div><label>Taxonomy:<label><select class="large" name="sTaxonomy" id="sTaxonomy"></select></div>', i) a += '<option value="' + i[o] + '" ' + (this.taxonomyData.value == i[o] ? "SELECTED" : "") + " >" + i[o] + "</option>", s += '<option value="' + i[o] + '" ' + (this.taxonomyData.text == i[o] ? "SELECTED" : "") + " >" + i[o] + "</option>";
                            return t += '<div><label>Attribute for values:<label><select class="large" name="sTaxonomyValue" id="sTaxonomyValue">' + a + "</select></div>", "pair" == e ? (this.taxonomyData.id = "", this.taxonomyData.slug = "", t += '<div><label>Attribute for texts:<label><select class="large" name="sTaxonomyText" id="sTaxonomyText">' + s + "</select></div>") : (t += '<div><label>Type a term ID:<label><input class="large" name="sTermId" id="sTermId" type="text" value="' + this.taxonomyData.id + '" /></div>', t += '<div><label>or type a term slug:<label><input class="large" name="sTermSlug" id="sTermSlug" type="text" value="' + this.taxonomyData.slug + '" /></div>'), t += '<div style="clear:both;"></div>'
                        },
                        events: function() {
                            $("#sTaxonomy").bind("change", {
                                obj: this
                            }, (function(e) {
                                e.data.obj.taxonomyData.taxonomy = $(this).val(), $.tzfdform.reloadItems()
                            })), $("#sTaxonomyText").bind("change", {
                                obj: this
                            }, (function(e) {
                                e.data.obj.taxonomyData.text = $(this).val(), $.tzfdform.reloadItems()
                            })), $("#sTaxonomyValue").bind("change", {
                                obj: this
                            }, (function(e) {
                                e.data.obj.taxonomyData.value = $(this).val(), $.tzfdform.reloadItems()
                            })), $("#sTermId").bind("keyup change", {
                                obj: this
                            }, (function(e) {
                                e.data.obj.taxonomyData.id = $.trim($(this).val()), $.tzfdform.reloadItems()
                            })), $("#sTermSlug").bind("keyup change", {
                                obj: this
                            }, (function(e) {
                                e.data.obj.taxonomyData.slug = $.trim($(this).val()), $.tzfdform.reloadItems()
                            })), this.loadTaxonomies()
                        }
                    },
                    user: {
                        title: "User Data",
                        userData: {
                            logged: !1,
                            text: "user_nicename",
                            value: "ID",
                            id: "",
                            login: ""
                        },
                        show: function(e) {
                            var t = "<div>Display for Users</div>",
                                i = ["ID", "user_login", "user_nicename", "display_name", "user_email"],
                                a = "",
                                s = "";
                            for (var o in i) a += '<option value="' + i[o] + '" ' + (this.userData.value == i[o] ? "SELECTED" : "") + " >" + i[o] + "</option>", s += '<option value="' + i[o] + '" ' + (this.userData.text == i[o] ? "SELECTED" : "") + " >" + i[o] + "</option>";
                            return t += '<div><label>Attribute for values:<label><select class="large" name="sUserValue" id="sUserValue">' + a + "</select></div>", "pair" == e ? (this.userData.logged = !1, this.userData.id = "", this.userData.login = "", t += '<div><label>Attribute for texts:<label><select class="large" name="sUserText" id="sUserText">' + s + "</select></div>") : (this.userData.text = "", t += '<div><label>Display data of logged user:<label><input name="sUserLogged" id="sUserLogged" type="checkbox" ' + (this.userData.logged ? "CHECKED" : "") + " /></div>", t += '<div><label> or display data of user ID:<label><input class="large" name="sUserId" id="sUserId" type="text" value="' + this.userData.id + '" ' + (this.userData.logged ? "DISABLED" : "") + " /></div>", t += '<div><label> or display data of user with user login:<label><input class="large" name="sUserLogin" id="sUserLogin" type="text" value="' + this.userData.login + '" ' + (this.userData.logged ? "DISABLED" : "") + " /></div>"), t += '<div style="clear:both;"></div>'
                        },
                        events: function() {
                            $("#sUserValue").bind("change", {
                                obj: this
                            }, (function(e) {
                                e.data.obj.userData.value = $(this).val(), $.tzfdform.reloadItems()
                            })), $("#sUserText").bind("change", {
                                obj: this
                            }, (function(e) {
                                e.data.obj.userData.text = $(this).val(), $.tzfdform.reloadItems()
                            })), $("#sUserLogged").bind("click", {
                                obj: this
                            }, (function(e) {
                                var t = $(this).is(":checked");
                                e.data.obj.userData.logged = t, $.tzfdform.reloadItems(), $("#sUserId").attr("disabled", t), $("#sUserLogin").attr("disabled", t)
                            })), $("#sUserId").bind("keyup change", {
                                obj: this
                            }, (function(e) {
                                e.data.obj.userData.id = $.trim($(this).val()), $.tzfdform.reloadItems()
                            })), $("#sUserLogin").bind("keyup change", {
                                obj: this
                            }, (function(e) {
                                e.data.obj.userData.login = $.trim($(this).val()), $.tzfdform.reloadItems()
                            }))
                        }
                    }
                }
            }, $.tzfdform.controls.datasource.prototype = {
                isDataSource: !0,
                active: "",
                editItemEventsDS: function() {
                    for (var e in this.list) this.list[e].events();
                    $("#sDataSource").bind("change", {
                        obj: this
                    }, (function(e) {
                        e.data.obj.active = $(this).val(), $.tzfdform.editItem(e.data.obj.index), $.tzfdform.reloadItems()
                    }))
                },
                showDataSource: function(e, t) {
                    "" == this.active && (this.active = e[0]);
                    var i = '<div style="border:1px dashed #888;padding:5px;position:relative;"><label>Define Datasource</label><div><select class="large" name="sDataSource" id="sDataSource">';
                    for (var a in e) i += '<option value="' + e[a] + '" ' + (e[a] == this.active ? "SELECTED" : "") + " >" + this.list[e[a]].title + "</option>";
                    return i += '</select></div><div id="datasourceSettings">' + this.list[this.active].show(t) + "</div></div>"
                }
            }, $.tzfdform.typeList.push({
                id: "ftextds",
                name: "Line Text DS",
                control_category: 20
            }), $.tzfdform.controls.ftextds = function() {
                this.init()
            }, $.extend($.tzfdform.controls.ftextds.prototype, $.tzfdform.controls.ftext.prototype, {
                ftype: "ftextds",
                display: function() {
                    return '<div class="fields ' + this.name + '" id="field' + this.form_identifier + '-' + this.index + '"><div class="arrow ui-icon ui-icon-play "></div><div title="Delete" class="remove ui-icon ui-icon-trash "></div><div title="Duplicate" class="copy ui-icon ui-icon-copy "></div><label>' + $.tzfdform.htmlEncode(this.title) + '' + ((this.required) ? "*" : "") + '</label><div class="dfield"><span class="uh">' + $.tzfdform.htmlEncode(this.userhelp) + '</span></div><div class="clearer"></div></div>'
                },
                editItemEvents: function() {
                    $.tzfdform.controls.ftext.prototype.editItemEvents.call(this)
                }
            }), $.tzfdform.typeList.push({
                id: "femailds",
                name: "Email DS",
                control_category: 20
            }), $.tzfdform.controls.femailds = function() {
                this.init()
            }, $.extend($.tzfdform.controls.femailds.prototype, $.tzfdform.controls.femail.prototype, {
                ftype: "femailds",
                display: function() {
                    return '<div class="fields ' + this.name + '" id="field' + this.form_identifier + '-' + this.index + '"><div class="arrow ui-icon ui-icon-play "></div><div title="Delete" class="remove ui-icon ui-icon-trash "></div><div title="Duplicate" class="copy ui-icon ui-icon-copy "></div><label>' + $.tzfdform.htmlEncode(this.title) + '' + ((this.required) ? "*" : "") + '</label><div class="dfield"><span class="uh">' + $.tzfdform.htmlEncode(this.userhelp) + '</span></div><div class="clearer"></div></div>'
                },
                editItemEvents: function() {
                    $.tzfdform.controls.femail.prototype.editItemEvents.call(this)
                }
            }), $.tzfdform.typeList.push({
                id: "ftextareads",
                name: "Text Area DS",
                control_category: 20
            }), $.tzfdform.controls.ftextareads = function() {
                this.init()
            }, $.extend($.tzfdform.controls.ftextareads.prototype, $.tzfdform.controls.ftextarea.prototype, {
                ftype: "ftextareads",
                display: function() {
                    return '<div class="fields ' + this.name + '" id="field' + this.form_identifier + '-' + this.index + '"><div class="arrow ui-icon ui-icon-play "></div><div title="Delete" class="remove ui-icon ui-icon-trash "></div><div title="Duplicate" class="copy ui-icon ui-icon-copy "></div><label>' + $.tzfdform.htmlEncode(this.title) + '' + ((this.required) ? "*" : "") + '</label><div class="dfield"><span class="uh">' + $.tzfdform.htmlEncode(this.userhelp) + '</span></div><div class="clearer"></div></div>'
                },
                editItemEvents: function() {
                    $.tzfdform.controls.ftextarea.prototype.editItemEvents.call(this)
                }
            }), $.tzfdform.typeList.push({
                id: "fcheckds",
                name: "Checkboxes DS",
                control_category: 20
            }), $.tzfdform.controls.fcheckds = function() {
                this.init()
            }, $.extend($.tzfdform.controls.fcheckds.prototype, $.tzfdform.controls.fcheck.prototype, {
                ftype: "fcheckds",
                display: function() {
                    return '<div class="fields ' + this.name + '" id="field' + this.form_identifier + '-' + this.index + '"><div class="arrow ui-icon ui-icon-play "></div><div title="Delete" class="remove ui-icon ui-icon-trash "></div><div title="Duplicate" class="copy ui-icon ui-icon-copy "></div><label>' + $.tzfdform.htmlEncode(this.title) + '' + ((this.required) ? "*" : "") + '</label><div class="dfield"><span class="uh">' + $.tzfdform.htmlEncode(this.userhelp) + '</span></div><div class="clearer"></div></div>'
                },
                editItemEvents: function() {
                    $.tzfdform.controls.fcheck.prototype.editItemEvents.call(this)
                }
            }), $.tzfdform.typeList.push({
                id: "fradiods",
                name: "Radio Btns DS",
                control_category: 20
            }), $.tzfdform.controls.fradiods = function() {
                this.init()
            }, $.extend($.tzfdform.controls.fradiods.prototype, $.tzfdform.controls.fradio.prototype, {
                ftype: "fradiods",
                display: function() {
                    return '<div class="fields ' + this.name + '" id="field' + this.form_identifier + '-' + this.index + '"><div class="arrow ui-icon ui-icon-play "></div><div title="Delete" class="remove ui-icon ui-icon-trash "></div><div title="Duplicate" class="copy ui-icon ui-icon-copy "></div><label>' + $.tzfdform.htmlEncode(this.title) + '' + ((this.required) ? "*" : "") + '</label><div class="dfield"><span class="uh">' + $.tzfdform.htmlEncode(this.userhelp) + '</span></div><div class="clearer"></div></div>'
                },
                editItemEvents: function() {
                    $.tzfdform.controls.fradio.prototype.editItemEvents.call(this)
                }
            }), $.tzfdform.typeList.push({
                id: "fPhoneds",
                name: "Phone DS",
                control_category: 20
            }), $.tzfdform.controls.fPhoneds = function() {
                this.init()
            }, $.extend($.tzfdform.controls.fPhoneds.prototype, $.tzfdform.controls.fPhone.prototype, {
                ftype: "fPhoneds",
                display: function() {
                    return '<div class="fields ' + this.name + '" id="field' + this.form_identifier + '-' + this.index + '"><div class="arrow ui-icon ui-icon-play "></div><div title="Delete" class="remove ui-icon ui-icon-trash "></div><div title="Duplicate" class="copy ui-icon ui-icon-copy "></div><label>' + $.tzfdform.htmlEncode(this.title) + '' + ((this.required) ? "*" : "") + '</label><div class="dfield"><span class="uh">' + $.tzfdform.htmlEncode(this.userhelp) + '</span></div><div class="clearer"></div></div>'
                },
                editItemEvents: function() {
                    $.tzfdform.controls.fPhone.prototype.editItemEvents.call(this)
                }
            }), $.tzfdform.typeList.push({
                id: "fdropdownds",
                name: "Dropdown DS",
                control_category: 20
            }), $.tzfdform.controls.fdropdownds = function() {
                this.init()
            }, $.extend($.tzfdform.controls.fdropdownds.prototype, $.tzfdform.controls.fdropdown.prototype, {
                ftype: "fdropdownds",
                display: function() {
                    return '<div class="fields ' + this.name + '" id="field' + this.form_identifier + '-' + this.index + '"><div class="arrow ui-icon ui-icon-play "></div><div title="Delete" class="remove ui-icon ui-icon-trash "></div><div title="Duplicate" class="copy ui-icon ui-icon-copy "></div><label>' + $.tzfdform.htmlEncode(this.title) + '' + ((this.required) ? "*" : "") + '</label><div class="dfield"><span class="uh">' + $.tzfdform.htmlEncode(this.userhelp) + '</span></div><div class="clearer"></div></div>'
                },
                editItemEvents: function() {
                    $.tzfdform.controls.fdropdown.prototype.editItemEvents.call(this)
                }
            }), $.tzfdform.typeList.push({
                id: "fhiddends",
                name: "Hidden DS",
                control_category: 20
            }), $.tzfdform.controls.fhiddends = function() {
                this.init()
            }, $.extend($.tzfdform.controls.fhiddends.prototype, $.tzfdform.controls.fhidden.prototype, {
                ftype: "fhiddends",
                display: function() {
                    return '<div class="fields ' + this.name + '" id="field' + this.form_identifier + '-' + this.index + '"><div class="arrow ui-icon ui-icon-play "></div><div title="Delete" class="remove ui-icon ui-icon-trash "></div><div title="Duplicate" class="copy ui-icon ui-icon-copy "></div><label>' + $.tzfdform.htmlEncode(this.title) + '' + ((this.required) ? "*" : "") + '</label><div class="dfield"><span class="uh">' + $.tzfdform.htmlEncode(this.userhelp) + '</span></div><div class="clearer"></div></div>'
                },
                editItemEvents: function() {
                    $.tzfdform.controls.fhidden.prototype.editItemEvents.call(this), this.editItemEventsDS()
                }
            }), $.tzfdform.controls.fcontainer = function() {}, $.extend($.tzfdform.controls.fcontainer.prototype, $.tzfdform.controls.ffields.prototype, {
                fields: [],
                columns: 1,
                editItemEvents: function() {
                    $.tzfdform.controls.ffields.prototype.editItemEvents.call(this), $("#sColumns").bind("change", {
                        obj: this
                    }, (function(e) {
                        e.data.obj.columns = $(this).val(), $.tzfdform.reloadItems()
                    }))
                },
                showShortLabel: function() {
                    return ""
                },
                showUserhelp: function() {
                    return ""
                },
                showSpecialDataInstance: function() {
                    for (var e = [1, 2, 3, 4], t = "", i = 0, a = e.length; i < a; i++) t += '<option value="' + e[i] + '" ' + (this.columns == e[i] ? "SELECTED" : "") + ">" + e[i] + " column" + (i ? "s" : "") + "</option>";
                    return '<div><label>Columns</label><br /><select name="sColumns" id="sColumns">' + t + '</select><div class="clearer"><span class="uh">Shown in columns the fields into the container.</span></div></div>'
                },
                remove: function() {
                    for (var e = this.fields.length - 1; e >= 0; e--) this.fBuild.removeItem($("." + this.fields[e]).attr("id").replace("field-", ""))
                },
                duplicateItem: function(e, t) {
                    for (var i = 0, a = this.fields.length; i < a; i++)
                        if (this.fields[i] == e) return void this.fields.splice(i + 1, 0, t)
                },
                addItem: function(e) {
                    this.fields.push(e)
                },
                after_show: function() {
                    for (var e = this, t = $("#field" + e.form_identifier + "-" + e.index + " .fcontainer"), i = [], a = 0, s = e.fields.length; a < s; a++) {
                        var o = $("." + e.fields[a]);
                        o.length && (o.detach().appendTo(t), i.push(e.fields[a]))
                    }
                    e.fields = i, t.sortable({
                        connectWith: ".ui-sortable",
                        items: ".fields",
                        update: function(t, i) {
                            var a = i.item.parents(".fields");
                            a.length && $(this).parents(".fields").attr("id") == a.attr("id") ? (e.fields = [], $(t.target).children(".fields").each((function() {
                                e.fields.push(/fieldname\d+/.exec($(this).attr("class"))[0])
                            })), $.tzfdform.reloadItems()) : e.fields.splice($.inArray(e.fBuild.getItems()[i.item.attr("id").replace("field-", "")].name, e.fields), 1)
                        }
                    })
                }
            }), $.tzfdform.typeList.push({
                id: "ffieldset",
                name: "Fieldset",
                control_category: 10
            }), $.tzfdform.controls.ffieldset = function() {}, $.extend($.tzfdform.controls.ffieldset.prototype, $.tzfdform.controls.fcontainer.prototype, {
                title: "Untitled",
                ftype: "ffieldset",
                fields: [],
                columns: 1,
                display: function() {
                    return '<div class="fields ' + this.name + '" id="field' + this.form_identifier + '-' + this.index + '"><div class="arrow ui-icon ui-icon-play "></div><div title="Delete" class="remove ui-icon ui-icon-trash "></div><div title="Duplicate" class="copy ui-icon ui-icon-copy "></div><label>' + $.tzfdform.htmlEncode(this.title) + '' + ((this.required) ? "*" : "") + '</label><div class="dfield"><span class="uh">' + $.tzfdform.htmlEncode(this.userhelp) + '</span></div><div class="clearer"></div></div>'
                },
                editItemEvents: function() {
                    $.tzfdform.controls.fcontainer.prototype.editItemEvents.call(this)
                }
            }), $.tzfdform.typeList.push({
                id: "fdiv",
                name: "Div",
                control_category: 10
            }), $.tzfdform.controls.fdiv = function() {}, $.extend($.tzfdform.controls.fdiv.prototype, $.tzfdform.controls.fcontainer.prototype, {
                title: "div",
                ftype: "fdiv",
                fields: [],
                columns: 1,
                display: function() {
                    return '<div class="fields ' + this.name + '" id="field' + this.form_identifier + '-' + this.index + '"><div class="arrow ui-icon ui-icon-play "></div><div title="Delete" class="remove ui-icon ui-icon-trash "></div><div title="Duplicate" class="copy ui-icon ui-icon-copy "></div><label>' + $.tzfdform.htmlEncode(this.title) + '' + ((this.required) ? "*" : "") + '</label><div class="dfield"><span class="uh">' + $.tzfdform.htmlEncode(this.userhelp) + '</span></div><div class="clearer"></div></div>'
                },
                editItemEvents: function() {
                    $.tzfdform.controls.fcontainer.prototype.editItemEvents.call(this)
                }
            }), $.tzfdform.typeList.push({
                id: "fMedia",
                name: "Media",
                control_category: 3
            }), $.tzfdform.controls.fMedia = function() {}, $.extend($.tzfdform.controls.fMedia.prototype, $.tzfdform.controls.ffields.prototype, {
                ftype: "fMedia",
                sMediaType: "image",
                display: function() {
                    return '<div class="fields fmark ' + this.name + '" id="field' + this.form_identifier + "-" + this.index + '" style><div class="arrow ui-icon ui-icon-play "></div><div title="Delete" class="remove ui-icon ui-icon-trash "></div><div title="Duplicate" class="copy ui-icon ui-icon-copy "></div><label>' + this["_display_" + this.sMediaType]() + '</label><span class="uh">' + this.data[this.sMediaType].sFigcaption + '</span><div class="clearer"></div></div>'
                },
                editItemEvents: function() {
                    $.tzfdform.controls.fPhone.prototype.editItemEvents.call(this)
                }
            }), $.tzfdform.typeList.push({
                id: "fButton",
                name: "Button",
                control_category: 3
            }), $.tzfdform.controls.fButton = function() {}, $.extend($.tzfdform.controls.fButton.prototype, $.tzfdform.controls.ffields.prototype, {
                ftype: "fButton",
                sType: "button",
                sValue: "button",
                sOnclick: "",
                userhelp: "A description of the section goes here.",
                display: function() {
                    return '<div class="fields ' + this.name + '" id="field' + this.form_identifier + '-' + this.index + '"><div class="arrow ui-icon ui-icon-play "></div><div title="Delete" class="remove ui-icon ui-icon-trash "></div><div title="Duplicate" class="copy ui-icon ui-icon-copy "></div><label>' + $.tzfdform.htmlEncode(this.title) + '' + ((this.required) ? "*" : "") + '</label><div class="dfield"><span class="uh">' + $.tzfdform.htmlEncode(this.userhelp) + '</span></div><div class="clearer"></div></div>'
                },
                editItemEvents: function() {
                    $.tzfdform.controls.ffields.prototype.editItemEvents.call(this)
                }
            }), $.tzfdform.typeList.push({
                id: "fnumberds",
                name: "Number DS",
                control_category: 20
            }), $.tzfdform.controls.fnumberds = function() {
                this.init()
            }, $.extend($.tzfdform.controls.fnumberds.prototype, $.tzfdform.controls.fnumber.prototype, {
                ftype: "fnumberds",
                init: function() {
                    $.extend(!0, this, new $.tzfdform.controls.datasource)
                },
                display: function() {
                    return $.tzfdform.controls.fnumber.prototype.display.call(this)
                },
                editItemEvents: function() {
                    $.tzfdform.controls.fnumber.prototype.editItemEvents.call(this), this.editItemEventsDS()
                },
                showAllSettings: function() {
                    return $.tzfdform.controls.fnumber.prototype.showAllSettings.call(this) + this.showDataSource(["database", "posttype", "taxonomy", "user"], "single")
                },
                showPredefined: function() {
                    return ""
                }
            }), $.tzfdform.typeList.push({
                id: "fhtml",
                name: "HTML content",
                control_category: 3
            }), $.tzfdform.controls.fhtml = function() {
                this.init()
            }, $.extend($.tzfdform.controls.fhtml.prototype, $.tzfdform.controls.ffields.prototype, {
                ftype: "fhtml",
                fcontent: "",
                display: function() {
                    return '<div class="fields ' + this.name + '" id="field' + this.form_identifier + '-' + this.index + '"><div class="arrow ui-icon ui-icon-play "></div><div title="Delete" class="remove ui-icon ui-icon-trash "></div><div title="Duplicate" class="copy ui-icon ui-icon-copy "></div><label>' + $.tzfdform.htmlEncode(this.title) + '' + ((this.required) ? "*" : "") + '</label><div class="dfield"><span class="uh">' + $.tzfdform.htmlEncode(this.userhelp) + '</span></div><div class="clearer"></div></div>'
                },
                editItemEvents: function() {
                    $.tzfdform.controls.ffields.prototype.editItemEvents.call(this)
                }
            }), $.tzfdform.typeList.push({
                id: "fcurrencyds",
                name: "Currency DS",
                control_category: 20
            }), $.tzfdform.controls.fcurrencyds = function() {
                this.init()
            }, $.extend($.tzfdform.controls.fcurrencyds.prototype, $.tzfdform.controls.fcurrency.prototype, {
                ftype: "fcurrencyds",
                init: function() {
                    $.extend(!0, this, new $.tzfdform.controls.datasource)
                },
                display: function() {
                    return $.tzfdform.controls.fcurrency.prototype.display.call(this)
                },
                editItemEvents: function() {
                    $.tzfdform.controls.fcurrency.prototype.editItemEvents.call(this), this.editItemEventsDS()
                },
                showAllSettings: function() {
                    return $.tzfdform.controls.fcurrency.prototype.showAllSettings.call(this) + this.showDataSource(["database", "posttype", "taxonomy", "user"], "single")
                },
                showPredefined: function() {
                    return ""
                }
            }), $('[name="paypal_recurrent"]').change((function() {
                "field" == $(this).val() ? $('[name="paypal_recurrent_field"]').show() : $('[name="paypal_recurrent_field"]').hide()
            }))
    })(tzfdformjQuery)
}));
