/** Integer cents keep automatic allocation identical to the server's FIFO rule. */
(function(root) {
    const allocate = (orders, cents) => orders.map(order => {
        const amount = Math.max(0, Math.min(order.due, cents)); cents -= amount;
        return {...order, allocated: amount};
    });
    if (typeof module !== 'undefined') module.exports = {allocate};
    else root.receiptAllocation = {allocate};
})(typeof window !== 'undefined' ? window : globalThis);
