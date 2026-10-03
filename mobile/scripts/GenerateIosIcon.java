import java.awt.Color;
import java.awt.Graphics2D;
import java.awt.RenderingHints;
import java.awt.image.BufferedImage;
import java.nio.file.Path;
import javax.imageio.ImageIO;

// Run from mobile/: java -Djava.awt.headless=true scripts/GenerateIosIcon.java
class GenerateIosIcon {
    public static void main(String[] args) throws Exception {
        Path source = Path.of("src/assets/jucans_logo.png");
        Path output = Path.of("ios/App/App/Assets.xcassets/AppIcon.appiconset/AppIcon-512@2x.png");
        BufferedImage logo = ImageIO.read(source.toFile());
        if (logo == null) throw new IllegalArgumentException("Cannot read " + source);

        int size = 1024;
        // A small inset preserves the artwork beneath iOS's own rounded mask.
        double scale = size * 0.90 / Math.max(logo.getWidth(), logo.getHeight());
        int width = (int) Math.round(logo.getWidth() * scale);
        int height = (int) Math.round(logo.getHeight() * scale);
        BufferedImage icon = new BufferedImage(size, size, BufferedImage.TYPE_INT_RGB);
        Graphics2D graphics = icon.createGraphics();
        graphics.setColor(Color.WHITE);
        graphics.fillRect(0, 0, size, size);
        graphics.setRenderingHint(RenderingHints.KEY_INTERPOLATION, RenderingHints.VALUE_INTERPOLATION_BICUBIC);
        graphics.setRenderingHint(RenderingHints.KEY_RENDERING, RenderingHints.VALUE_RENDER_QUALITY);
        graphics.drawImage(logo, (size - width) / 2, (size - height) / 2, width, height, null);
        graphics.dispose();
        ImageIO.write(icon, "png", output.toFile());

        BufferedImage saved = ImageIO.read(output.toFile());
        if (saved.getWidth() != size || saved.getHeight() != size || saved.getColorModel().hasAlpha()) {
            throw new IllegalStateException("iOS icon must be an opaque 1024x1024 image");
        }
        System.out.println("Generated opaque 1024x1024 iOS AppIcon from " + source);
    }
}
