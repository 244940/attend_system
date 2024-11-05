import face_recognition
import sys
import numpy as np
import os

def process_image(image_path):
    # Check if the file exists
    if not os.path.exists(image_path):
        return "Image file does not exist"

    try:
        # Load the image
        image = face_recognition.load_image_file(image_path)
    except Exception as e:
        return f"Error loading image: {str(e)}"
    
    # Check the size of the image
    height, width, _ = image.shape
    print(f"Image loaded successfully: {image_path}")
    print(f"Image dimensions: {width}x{height}")
    
    # Generate the face encoding
    face_encodings = face_recognition.face_encodings(image)
    
    if len(face_encodings) == 0:
        print("No faces found in the image.")
        return "No face found"
    
    print(f"Number of faces found: {len(face_encodings)}")

    # Take the first face encoding if multiple faces are found
    face_encoding = face_encodings[0]
    
    # Return the face encoding as binary data for storage
    #return np.array(face_encoding).tobytes()
    # Return the face encoding as a string
    #return ','.join([str(x) for x in face_encoding])
    
    # Debug: Print the length of the encoding in bytes
    encoding_bytes = np.array(face_encoding).tobytes()
    print(f"Face encoding length in bytes: {len(encoding_bytes)}")  # Should be 1024 bytes
    
    # Return the face encoding as binary data for storage
    return encoding_bytes

if __name__ == "__main__":
    image_path = sys.argv[1]
    
    if not os.path.exists(image_path):
        print("Image file does not exist")
        sys.exit(1)
    
    encoding_result = process_image(image_path)
    print(encoding_result)
