class FerrySpot {

    public animals: Animal[];

    constructor(
        private game: NoahGame,
        public position: number,
        ferry: Ferry,
    ) {
        this.animals = ferry.animals;
        
        let html = `
        <div id="ferry-spot-${position}" class="ferry-spot position${position}">
            <div class="stockitem ferry-card"></div>            
        `;
        this.animals.forEach((animal, index) => html += `
            <div id="ferry-spot-${position}-animal${animal.id}" class="animal-card" style="top : ${100 + index * 30}px; background-position: ${this.getBackgroundPosition(animal)}"></div>
        `);
        html += `</div>`;

        dojo.place(html, 'center-board');
    }

    private getBackgroundPosition(animal: Animal) {
        const imagePosition = animal.type >= 20 ?
            24 + (animal.type - 20) * 2 + animal.gender :
            (animal.type - 1) * 2 + animal.gender;
        const image_items_per_row = 10;
        var row = Math.floor(imagePosition / image_items_per_row);
        const xBackgroundPercent = (imagePosition - (row * image_items_per_row)) * 100;
        const yBackgroundPercent = row * 100;
        return `-${xBackgroundPercent}% -${yBackgroundPercent}%`;
    }

    public addAnimal(animal: Animal) {
        const html = `<div id="ferry-spot-${this.position}-animal${animal.id}" class="animal-card" style="top : ${100 + this.animals.length * 30}px; background-position: ${this.getBackgroundPosition(animal)}"></div>`;

        this.animals.push(animal);

        dojo.place(html, `ferry-spot-${this.position}`);
    }

    public removeAnimals() {
        this.animals.forEach(animal => dojo.destroy(`ferry-spot-${this.position}-animal${animal.id}`));
        this.animals = [];
    }
}