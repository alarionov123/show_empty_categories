{if !$hide_title}
{include file="common/subheader.tpl" title=__("categories.show_empty_categories") target="#acc_addon_show_when_empty"}
{/if}
<div id="acc_show_when_empty" class="collapsed in">
<div class="control-group {if $share_dont_hide}cm-no-hide-input{/if}">
    <label class="control-label" for="elm_show_when_empty">{__("show_when_empty")}:</label>
    <div class="controls">
        <input type="hidden" name="category_data[show_when_empty]" value="N" />
        <input id="elm_show_when_empty_{$id}" type="checkbox" name="category_data[show_when_empty]" value="Y" {if $category_data.show_when_empty == "Y"}checked="checked"{/if}/>
        <p class="muted description">{__("show_empty_categories.tt_views_categories_update_empty")}</p>
    </div>
    </div>
</div>
