<button type="submit" name="submit[project]" class="major">保存</button>
<select name="tags[]" class="chosen allow-new" data-placeholder="标签" multiple="multiple">
	<?=options($this->project->getAllTags(),$tags)?>
</select>