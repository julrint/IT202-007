<div id="balance-value">
    Points: <?php echo get_points(); ?>
</div>
<script>
    let bv = document.getElementById("balance-value");
    //I'll make this flexible so I can define various placeholders and copy
    //the value into all of them
    let placeholders = document.getElementsByClassName("show-balance");
    for (let p of placeholders) {
        //https://developer.mozilla.org/en-US/docs/Web/API/Node/cloneNode
        p.innerHTML = bv.outerHTML; //bv.cloneNode(true).outerHTML;
    }
    bv.remove(); //delete the original
</script>