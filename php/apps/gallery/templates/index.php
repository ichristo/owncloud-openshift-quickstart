<div id="controls">
	<div id='breadcrumbs'></div>
	<span class="right">
		<button class="share"><?php p($l->t("Share")); ?></button>
		<a class="share" data-item-type="folder" data-item="" title="<?php p($l->t("Share")); ?>"
		   data-possible-permissions="31"></a>
	</span>
</div>
<div id="gallery" class="hascontrols"></div>

<div id="emptycontent" class="hidden"><?php p($l->t("No pictures found! If you upload pictures in the files app, they will be displayed here.")); ?></div>

<input type="hidden" name="allowShareWithLink" id="allowShareWithLink" value="yes" />
